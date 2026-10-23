<?php

namespace KimaiPlugin\ClientReportBundle\WorkReport;

use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\Query\BaseQuery;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Builds the internal work report: who worked on what, how long, day by day.
 *
 * The result is a plain array, the same one the panel renders, the PDF prints and
 * the API returns as JSON. Every total is calculated here once, from one list of
 * entries, and cross-checked before it leaves: the sums per person, per day, per
 * project, per customer and per type of work must all equal the grand total to the
 * second. If they do not, the report is refused instead of shown - a reader (or an
 * agent writing a client report) must never get numbers that do not add up.
 *
 * Rules worth knowing when reading the numbers:
 * - an entry belongs to the day it begins on, in the report timezone, the same way
 *   Kimai filters entries by date
 * - running entries are listed as warnings and are NOT part of any total
 * - all durations are whole seconds; "hm" strings are cut to full minutes
 */
final class WorkReportBuilder
{
    public const LONG_ENTRY_SECONDS = 10 * 3600;
    public const LONG_DAY_SECONDS = 12 * 3600;

    public function __construct(
        private readonly TimesheetRepository $repository,
        private readonly AuthorizationCheckerInterface $security,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(WorkReportQuery $query, User $viewer): array
    {
        $timesheets = $this->fetch($query, $viewer);
        $tz = $query->timezone;
        $showRates = $this->canSeeRates($query, $viewer);

        $totals = ['seconds' => 0, 'billable' => 0, 'entries' => 0];
        $days = [];
        $cursor = $query->begin;
        while ($cursor <= $query->end) {
            $days[$cursor->format('Y-m-d')] = ['seconds' => 0, 'billable' => 0, 'entries' => 0];
            $cursor = $cursor->modify('+1 day');
        }

        $users = [];
        $projects = [];
        $customers = [];
        $activities = [];
        $tags = [];
        $amounts = [];
        $entries = [];
        $warnings = [
            'running' => [],
            'missingDescription' => [],
            'overlaps' => [],
            'longEntries' => [],
            'longDays' => [],
            'crossMidnight' => [],
        ];
        $perUserEntries = [];

        foreach ($timesheets as $timesheet) {
            $user = $timesheet->getUser();
            $project = $timesheet->getProject();
            $customer = $project?->getCustomer();
            $activity = $timesheet->getActivity();
            $begin = $this->local($timesheet->getBegin(), $tz);
            $end = $this->local($timesheet->getEnd(), $tz);

            if ($user === null || $begin === null) {
                continue;
            }

            $date = $begin->format('Y-m-d');
            $userRef = $this->userRef($user);

            if ($timesheet->isRunning()) {
                $warnings['running'][] = [
                    'entryId' => $timesheet->getId(),
                    'user' => $userRef,
                    'date' => $date,
                    'begin' => $begin->format(\DATE_ATOM),
                    'elapsedSeconds' => max(0, (new \DateTimeImmutable('now', $tz))->getTimestamp() - $begin->getTimestamp()),
                    'project' => $project?->getName(),
                    'description' => $timesheet->getDescription(),
                ];
                continue;
            }

            $seconds = max(0, (int) ($timesheet->getDuration(false) ?? 0));
            $billable = $timesheet->isBillable();
            $paid = $billable ? $seconds : 0;

            $totals['seconds'] += $seconds;
            $totals['billable'] += $paid;
            $totals['entries']++;

            if (!isset($days[$date])) {
                // cannot happen with Kimai's own date filter, but a total that silently
                // lost a day would be worse than an error
                throw new \LogicException(\sprintf('Entry %d begins on %s, outside the report period.', $timesheet->getId(), $date));
            }
            $this->add($days[$date], $seconds, $paid);

            $uid = $user->getId();
            $users[$uid] ??= $userRef + ['seconds' => 0, 'billable' => 0, 'entries' => 0, 'days' => [], 'projects' => []];
            $this->add($users[$uid], $seconds, $paid);
            $users[$uid]['days'][$date] = ($users[$uid]['days'][$date] ?? 0) + $seconds;

            $pid = $project?->getId() ?? 0;
            $projects[$pid] ??= [
                'id' => $project?->getId(),
                'name' => $project?->getName(),
                'customer' => $customer !== null ? ['id' => $customer->getId(), 'name' => $customer->getName()] : null,
                'seconds' => 0, 'billable' => 0, 'entries' => 0, 'users' => [],
            ];
            $this->add($projects[$pid], $seconds, $paid);
            $projects[$pid]['users'][$uid] = ($projects[$pid]['users'][$uid] ?? 0) + $seconds;
            $users[$uid]['projects'][$pid] = ($users[$uid]['projects'][$pid] ?? 0) + $seconds;

            $cid = $customer?->getId() ?? 0;
            $customers[$cid] ??= ['id' => $customer?->getId(), 'name' => $customer?->getName(), 'seconds' => 0, 'billable' => 0, 'entries' => 0];
            $this->add($customers[$cid], $seconds, $paid);

            $aid = $activity?->getId() ?? 0;
            $activities[$aid] ??= ['id' => $activity?->getId(), 'name' => $activity?->getName(), 'seconds' => 0, 'billable' => 0, 'entries' => 0];
            $this->add($activities[$aid], $seconds, $paid);

            foreach ($timesheet->getTags() as $tag) {
                $tags[$tag->getId()] ??= ['id' => $tag->getId(), 'name' => $tag->getName(), 'seconds' => 0, 'billable' => 0, 'entries' => 0];
                $this->add($tags[$tag->getId()], $seconds, $paid);
            }

            $currency = $customer?->getCurrency() ?? '';
            if ($showRates) {
                $amounts[$currency] ??= ['currency' => $currency, 'rate' => 0.0, 'internalRate' => 0.0, 'billableRate' => 0.0];
                $amounts[$currency]['rate'] += $timesheet->getRate();
                $amounts[$currency]['internalRate'] += (float) $timesheet->getInternalRate();
                if ($billable) {
                    $amounts[$currency]['billableRate'] += $timesheet->getRate();
                }
            }

            $description = trim((string) $timesheet->getDescription());
            $entry = [
                'id' => $timesheet->getId(),
                'date' => $date,
                'begin' => $begin->format(\DATE_ATOM),
                'end' => $end?->format(\DATE_ATOM),
                'seconds' => $seconds,
                'hm' => self::hm($seconds),
                'user' => $userRef,
                'customer' => $customer !== null ? ['id' => $customer->getId(), 'name' => $customer->getName()] : null,
                'project' => $project !== null ? ['id' => $project->getId(), 'name' => $project->getName()] : null,
                'activity' => $activity !== null ? ['id' => $activity->getId(), 'name' => $activity->getName()] : null,
                'description' => $description,
                'tags' => $timesheet->getTagsAsArray(),
                'billable' => $billable,
                'exported' => $timesheet->isExported(),
            ];
            if ($showRates) {
                $entry['rate'] = $timesheet->getRate();
                $entry['internalRate'] = $timesheet->getInternalRate();
                $entry['hourlyRate'] = $timesheet->getHourlyRate();
                $entry['fixedRate'] = $timesheet->getFixedRate();
                $entry['currency'] = $currency;
            }
            $meta = [];
            foreach ($timesheet->getVisibleMetaFields() as $field) {
                $meta[$field->getName()] = $field->getValue();
            }
            if ($meta !== []) {
                $entry['meta'] = $meta;
            }
            $entries[] = $entry;
            $perUserEntries[$uid][] = $entry;

            if ($description === '') {
                $warnings['missingDescription'][] = $this->warning($entry);
            }
            if ($seconds > self::LONG_ENTRY_SECONDS) {
                $warnings['longEntries'][] = $this->warning($entry);
            }
            if ($end !== null && $end->format('Y-m-d') !== $date && $end->format('H:i:s') !== '00:00:00') {
                $warnings['crossMidnight'][] = $this->warning($entry);
            }
        }

        foreach ($perUserEntries as $list) {
            $warnings['overlaps'] = array_merge($warnings['overlaps'], $this->overlaps($list));
        }
        foreach ($users as $user) {
            foreach ($user['days'] as $date => $seconds) {
                if ($seconds > self::LONG_DAY_SECONDS) {
                    $warnings['longDays'][] = ['user' => $this->stripUser($user), 'date' => $date, 'seconds' => $seconds, 'hm' => self::hm($seconds)];
                }
            }
        }

        $this->assertConsistent($totals, $days, $users, $projects, $customers, $activities, $entries);

        $report = [
            'meta' => [
                'generatedAt' => (new \DateTimeImmutable('now', $tz))->format(\DATE_ATOM),
                'timezone' => $tz->getName(),
                'period' => $query->period,
                'begin' => $query->begin->format('Y-m-d'),
                'end' => $query->end->format('Y-m-d'),
                'days' => $query->getDayCount(),
                'filters' => $this->describeFilters($query),
                'ratesIncluded' => $showRates,
                'rules' => [
                    'Durations are whole seconds; hm strings are cut to full minutes, hours are rounded to 2 decimals.',
                    'An entry counts on the day it begins, in the report timezone.',
                    'Running entries are listed under warnings.running and are not part of any total.',
                    'Tag totals overlap: an entry with two tags counts under both.',
                ],
            ],
            'totals' => $this->durations($totals['seconds'], $totals['billable']) + [
                'entries' => $totals['entries'],
                'users' => \count($users),
                'daysWithTime' => \count(array_filter($days, static fn (array $d): bool => $d['entries'] > 0)),
                'runningExcluded' => \count($warnings['running']),
            ],
            'days' => $this->days($days),
            'users' => $this->users($users, $projects, $totals['seconds']),
            'projects' => $this->projects($projects, $users, $totals['seconds']),
            'customers' => $this->ranked($customers, $totals['seconds']),
            'activities' => $this->ranked($activities, $totals['seconds']),
            'tags' => $this->ranked($tags, $totals['seconds']),
            'warnings' => $warnings,
            'warningCount' => array_sum(array_map('count', $warnings)),
            'integrity' => [
                'ok' => true,
                'entriesCounted' => $totals['entries'],
                'sumOfEntrySeconds' => array_sum(array_column($entries, 'seconds')),
                'checked' => ['users', 'days', 'projects', 'customers', 'activities', 'entries'],
            ],
        ];

        if ($showRates) {
            $report['amounts'] = array_values(array_map(static fn (array $a): array => [
                'currency' => $a['currency'],
                'rate' => round($a['rate'], 2),
                'billableRate' => round($a['billableRate'], 2),
                'internalRate' => round($a['internalRate'], 2),
            ], $amounts));
        }

        if ($query->includeEntries) {
            $report['entries'] = $entries;
        }

        return $report;
    }

    public static function hm(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return \sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * @return array<Timesheet>
     */
    private function fetch(WorkReportQuery $query, User $viewer): array
    {
        $users = [];
        foreach ($query->users as $user) {
            $users[$user->getId()] = $user;
        }
        foreach ($query->teams as $team) {
            foreach ($team->getUsers() as $member) {
                $users[$member->getId()] = $member;
            }
        }

        // a team filter that resolves to nobody must give an empty report, not fall
        // back to "no user filter", which Kimai would read as "everybody"
        if ($query->teams !== [] && $users === []) {
            return [];
        }

        $tq = new TimesheetQuery(false);
        $tq->setBegin(\DateTime::createFromImmutable($query->begin));
        $tq->setEnd(\DateTime::createFromImmutable($query->end));
        $tq->setState(TimesheetQuery::STATE_ALL);
        $tq->setExported(TimesheetQuery::STATE_ALL);
        $tq->setOrderBy('begin');
        $tq->setOrder(BaseQuery::ORDER_ASC);
        // limits the result to what the viewer may see (own teams for a teamlead)
        $tq->setCurrentUser($viewer);

        foreach ($users as $user) {
            $tq->addUser($user);
        }
        foreach ($query->customers as $customer) {
            $tq->addCustomer($customer);
        }
        foreach ($query->projects as $project) {
            $tq->addProject($project);
        }
        if ($query->activities !== []) {
            $tq->setActivities($query->activities);
        }
        foreach ($query->tags as $tag) {
            $tq->addTag($tag);
        }
        if ($query->billable !== null) {
            $tq->setBillable($query->billable);
        }

        return $this->repository->getTimesheetsForQuery($tq, true);
    }

    private function canSeeRates(WorkReportQuery $query, User $viewer): bool
    {
        if ($this->security->isGranted('view_rate_other_timesheet')) {
            return true;
        }

        $onlySelf = \count($query->users) === 1 && $query->users[0]->getId() === $viewer->getId();

        return $onlySelf && $this->security->isGranted('view_rate_own_timesheet');
    }

    private function local(?\DateTimeInterface $date, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($date === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($date)->setTimezone($tz);
    }

    /**
     * @param array<string, mixed> $bucket
     */
    private function add(array &$bucket, int $seconds, int $paid): void
    {
        $bucket['seconds'] += $seconds;
        $bucket['billable'] += $paid;
        $bucket['entries']++;
    }

    /**
     * @return array{id: int|null, username: string, name: string}
     */
    private function userRef(User $user): array
    {
        return ['id' => $user->getId(), 'username' => $user->getUserIdentifier(), 'name' => $user->getDisplayName()];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{id: mixed, username: mixed, name: mixed}
     */
    private function stripUser(array $user): array
    {
        return ['id' => $user['id'], 'username' => $user['username'], 'name' => $user['name']];
    }

    /**
     * @return array<string, mixed>
     */
    private function durations(int $seconds, int $billable): array
    {
        return [
            'seconds' => $seconds,
            'hours' => round($seconds / 3600, 2),
            'hm' => self::hm($seconds),
            'billableSeconds' => $billable,
            'billableHours' => round($billable / 3600, 2),
            'billableHm' => self::hm($billable),
            'nonBillableSeconds' => $seconds - $billable,
            'nonBillableHm' => self::hm($seconds - $billable),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function warning(array $entry): array
    {
        return [
            'entryId' => $entry['id'],
            'user' => $entry['user'],
            'date' => $entry['date'],
            'begin' => $entry['begin'],
            'end' => $entry['end'],
            'seconds' => $entry['seconds'],
            'hm' => $entry['hm'],
            'project' => $entry['project']['name'] ?? null,
            'description' => $entry['description'],
        ];
    }

    /**
     * Entries of one person that run at the same time. The list is ordered by begin,
     * so it is enough to compare each entry with the latest end seen so far.
     *
     * @param array<array<string, mixed>> $list
     * @return array<array<string, mixed>>
     */
    private function overlaps(array $list): array
    {
        $result = [];
        $latest = null;

        foreach ($list as $entry) {
            if ($entry['end'] === null) {
                continue;
            }
            $begin = new \DateTimeImmutable($entry['begin']);
            $end = new \DateTimeImmutable($entry['end']);

            if ($latest !== null && $begin < $latest['end']) {
                $overlapEnd = min($end, $latest['end']);
                $result[] = [
                    'user' => $entry['user'],
                    'date' => $entry['date'],
                    'entryIds' => [$latest['entry']['id'], $entry['id']],
                    'seconds' => $overlapEnd->getTimestamp() - $begin->getTimestamp(),
                    'hm' => self::hm($overlapEnd->getTimestamp() - $begin->getTimestamp()),
                    'first' => ['id' => $latest['entry']['id'], 'begin' => $latest['entry']['begin'], 'end' => $latest['entry']['end'], 'project' => $latest['entry']['project']['name'] ?? null],
                    'second' => ['id' => $entry['id'], 'begin' => $entry['begin'], 'end' => $entry['end'], 'project' => $entry['project']['name'] ?? null],
                ];
            }

            if ($latest === null || $end > $latest['end']) {
                $latest = ['end' => $end, 'entry' => $entry];
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, int>> $days
     * @return array<array<string, mixed>>
     */
    private function days(array $days): array
    {
        $result = [];
        foreach ($days as $date => $day) {
            $result[] = ['date' => $date, 'weekday' => (int) (new \DateTimeImmutable($date))->format('N')]
                + $this->durations($day['seconds'], $day['billable'])
                + ['entries' => $day['entries']];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<string, mixed>> $projects
     * @return array<array<string, mixed>>
     */
    private function users(array $users, array $projects, int $total): array
    {
        uasort($users, static fn (array $a, array $b): int => [$b['seconds'], $a['name']] <=> [$a['seconds'], $b['name']]);

        $result = [];
        foreach ($users as $user) {
            arsort($user['projects']);
            $perProject = [];
            foreach ($user['projects'] as $pid => $seconds) {
                $perProject[] = [
                    'id' => $projects[$pid]['id'],
                    'name' => $projects[$pid]['name'],
                    'customer' => $projects[$pid]['customer']['name'] ?? null,
                    'seconds' => $seconds,
                    'hm' => self::hm($seconds),
                ];
            }
            ksort($user['days']);

            $result[] = $this->stripUser($user)
                + $this->durations($user['seconds'], $user['billable'])
                + [
                    'entries' => $user['entries'],
                    'share' => $total > 0 ? round($user['seconds'] / $total * 100, 2) : 0.0,
                    'days' => $user['days'],
                    'projects' => $perProject,
                ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     * @param array<int, array<string, mixed>> $users
     * @return array<array<string, mixed>>
     */
    private function projects(array $projects, array $users, int $total): array
    {
        uasort($projects, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        $result = [];
        foreach ($projects as $project) {
            arsort($project['users']);
            $perUser = [];
            foreach ($project['users'] as $uid => $seconds) {
                $perUser[] = $this->stripUser($users[$uid]) + ['seconds' => $seconds, 'hm' => self::hm($seconds)];
            }

            $result[] = [
                'id' => $project['id'],
                'name' => $project['name'],
                'customer' => $project['customer'],
            ] + $this->durations($project['seconds'], $project['billable']) + [
                'entries' => $project['entries'],
                'share' => $total > 0 ? round($project['seconds'] / $total * 100, 2) : 0.0,
                'users' => $perUser,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<array<string, mixed>>
     */
    private function ranked(array $items, int $total): array
    {
        uasort($items, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return array_values(array_map(fn (array $item): array => [
            'id' => $item['id'],
            'name' => $item['name'],
        ] + $this->durations($item['seconds'], $item['billable']) + [
            'entries' => $item['entries'],
            'share' => $total > 0 ? round($item['seconds'] / $total * 100, 2) : 0.0,
        ], $items));
    }

    /**
     * @return array<string, mixed>
     */
    private function describeFilters(WorkReportQuery $query): array
    {
        $named = static fn (array $items): array => array_map(static fn ($item): array => ['id' => $item->getId(), 'name' => $item->getName()], $items);

        return [
            'users' => array_map(fn (User $u): array => $this->userRef($u), $query->users),
            'teams' => $named($query->teams),
            'customers' => $named($query->customers),
            'projects' => $named($query->projects),
            'activities' => $named($query->activities),
            'tags' => $named($query->tags),
            'billable' => $query->billable,
        ];
    }

    /**
     * Every breakdown is summed again and compared with the grand total. Any
     * difference means a bug in this class, and a wrong report must not be shown.
     *
     * @param array<string, int> $totals
     * @param array<array<string, int>> ...$groups
     */
    private function assertConsistent(array $totals, array ...$groups): void
    {
        $names = ['days', 'users', 'projects', 'customers', 'activities', 'entries'];

        foreach ($groups as $i => $group) {
            $seconds = array_sum(array_column($group, 'seconds'));
            if ($seconds !== $totals['seconds']) {
                throw new \LogicException(\sprintf(
                    'Work report is inconsistent: %s add up to %d seconds, the total is %d.',
                    $names[$i],
                    $seconds,
                    $totals['seconds']
                ));
            }
            if ($names[$i] !== 'entries') {
                $billable = array_sum(array_column($group, 'billable'));
                if ($billable !== $totals['billable']) {
                    throw new \LogicException(\sprintf('Work report is inconsistent: billable %s add up to %d seconds, the total is %d.', $names[$i], $billable, $totals['billable']));
                }
            }
        }
    }
}
