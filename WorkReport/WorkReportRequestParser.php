<?php

namespace KimaiPlugin\ClientReportBundle\WorkReport;

use App\Entity\Team;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\TagRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Reads work report parameters from a query string. The panel, its PDF and the API
 * share this one parser, so a URL means the same thing everywhere.
 *
 * It is strict on purpose: an unknown id, a malformed date or a user the caller is
 * not allowed to see is an error, never silently dropped. A report that quietly
 * ignored half of its filters would show the wrong numbers with full confidence.
 */
final class WorkReportRequestParser
{
    public const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'];

    private const MAX_DAYS = 366;

    public function __construct(
        private readonly AuthorizationCheckerInterface $security,
        private readonly UserRepository $users,
        private readonly TeamRepository $teams,
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projects,
        private readonly ActivityRepository $activities,
        private readonly TagRepository $tags,
    ) {
    }

    public function parse(Request $request, User $viewer): WorkReportQuery
    {
        $query = $request->query;

        $timezone = $this->timezone((string) $query->get('timezone', ''), $viewer);
        [$begin, $end, $period] = $this->dates(
            trim((string) $query->get('period', '')),
            trim((string) $query->get('begin', '')),
            trim((string) $query->get('end', '')),
            $timezone,
            $viewer
        );

        $users = $this->resolveUsers($this->list($request, 'users'));
        $teams = $this->resolveTeams($this->list($request, 'teams'), $viewer);

        if (!$this->security->isGranted('view_other_timesheet')) {
            // without the permission to see other people's times, the only valid
            // report is your own - asking for anyone else is an error, not a fallback
            foreach ($users as $user) {
                if ($user->getId() !== $viewer->getId()) {
                    throw new InvalidWorkReportRequest(\sprintf('You may only report on your own time, not on user %d.', $user->getId()), 403);
                }
            }
            if ($teams !== []) {
                throw new InvalidWorkReportRequest('You may only report on your own time, team filters are not available.', 403);
            }
            $users = [$viewer];
        }

        return new WorkReportQuery(
            $begin,
            $end,
            $timezone,
            $period,
            $users,
            $teams,
            $this->resolve($this->list($request, 'customers'), 'customer', fn (int $id) => $this->customers->find($id)),
            $this->resolve($this->list($request, 'projects'), 'project', fn (int $id) => $this->projects->find($id)),
            $this->resolve($this->list($request, 'activities'), 'activity', fn (int $id) => $this->activities->find($id)),
            $this->resolveTags($this->list($request, 'tags')),
            $this->bool((string) $query->get('billable', ''), 'billable'),
            $this->bool((string) $query->get('entries', ''), 'entries') ?? true,
        );
    }

    /**
     * The default report when nothing was asked for: the current week of the viewer.
     */
    public function defaults(User $viewer): WorkReportQuery
    {
        $timezone = new \DateTimeZone($viewer->getTimezone());
        [$begin, $end] = $this->periodRange('this_week', $timezone, $viewer);

        return new WorkReportQuery(
            $begin,
            $end,
            $timezone,
            'this_week',
            $this->security->isGranted('view_other_timesheet') ? [] : [$viewer],
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function periodRange(string $period, \DateTimeZone $timezone, User $viewer): array
    {
        $today = new \DateTimeImmutable('today', $timezone);
        $weekStart = $viewer->isFirstDayOfWeekSunday() ? 'sunday' : 'monday';
        $thisWeek = $today->format('l') === ucfirst($weekStart) ? $today : $today->modify('last ' . $weekStart);

        [$first, $last] = match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
            'this_week' => [$thisWeek, $thisWeek->modify('+6 days')],
            'last_week' => [$thisWeek->modify('-7 days'), $thisWeek->modify('-1 day')],
            'this_month' => [$today->modify('first day of this month'), $today->modify('last day of this month')],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y'), 12, 31)],
            'last_year' => [$today->setDate((int) $today->format('Y') - 1, 1, 1), $today->setDate((int) $today->format('Y') - 1, 12, 31)],
            default => throw new InvalidWorkReportRequest(\sprintf('Unknown period "%s", use one of: %s.', $period, implode(', ', self::PERIODS))),
        };

        return [$first->setTime(0, 0, 0), $last->setTime(23, 59, 59)];
    }

    private function timezone(string $name, User $viewer): \DateTimeZone
    {
        if ($name === '') {
            return new \DateTimeZone($viewer->getTimezone());
        }

        try {
            return new \DateTimeZone($name);
        } catch (\Exception) {
            throw new InvalidWorkReportRequest(\sprintf('Unknown timezone "%s".', $name));
        }
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: ?string}
     */
    private function dates(string $period, string $begin, string $end, \DateTimeZone $timezone, User $viewer): array
    {
        if ($period !== '') {
            if ($begin !== '' || $end !== '') {
                throw new InvalidWorkReportRequest('Pass either "period" or "begin" and "end", not both.');
            }
            [$first, $last] = $this->periodRange($period, $timezone, $viewer);

            return [$first, $last, $period];
        }

        if ($begin === '' && $end === '') {
            [$first, $last] = $this->periodRange('this_week', $timezone, $viewer);

            return [$first, $last, 'this_week'];
        }

        if ($begin === '' || $end === '') {
            throw new InvalidWorkReportRequest('Both "begin" and "end" are required (format YYYY-MM-DD).');
        }

        $first = $this->date($begin, 'begin', $timezone)->setTime(0, 0, 0);
        $last = $this->date($end, 'end', $timezone)->setTime(23, 59, 59);

        if ($last < $first) {
            throw new InvalidWorkReportRequest('"end" is before "begin".');
        }
        if ($first->diff($last)->days + 1 > self::MAX_DAYS) {
            throw new InvalidWorkReportRequest(\sprintf('The period is longer than %d days, split it into smaller reports.', self::MAX_DAYS));
        }

        return [$first, $last, null];
    }

    private function date(string $value, string $field, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        // createFromFormat happily rolls 2026-02-31 over to March, which would be
        // a silently different report
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidWorkReportRequest(\sprintf('"%s" must be a date in the format YYYY-MM-DD, got "%s".', $field, $value));
        }

        return $date;
    }

    /**
     * Accepts both users[]=1&users[]=2 and users=1,2.
     *
     * @return array<string>
     */
    private function list(Request $request, string $key): array
    {
        $all = $request->query->all();
        $raw = $all[$key] ?? [];

        if (!\is_array($raw)) {
            $raw = explode(',', (string) $raw);
        }

        $values = [];
        foreach ($raw as $value) {
            if (!\is_scalar($value)) {
                throw new InvalidWorkReportRequest(\sprintf('"%s" must be a list of values.', $key));
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * Users may be given by id or by login name.
     *
     * @param array<string> $values
     * @return array<User>
     */
    private function resolveUsers(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $user = ctype_digit($value) ? $this->users->find((int) $value) : $this->users->findByUsername($value);
            if (!$user instanceof User) {
                throw new InvalidWorkReportRequest(\sprintf('Unknown user "%s".', $value), 404);
            }
            if (!$this->security->isGranted('access_user', $user)) {
                throw new InvalidWorkReportRequest(\sprintf('You may not see the times of user "%s".', $value), 403);
            }
            $result[$user->getId()] = $user;
        }

        return array_values($result);
    }

    /**
     * @param array<string> $values
     * @return array<Team>
     */
    private function resolveTeams(array $values, User $viewer): array
    {
        $result = [];
        foreach ($values as $value) {
            $team = ctype_digit($value) ? $this->teams->find((int) $value) : null;
            if (!$team instanceof Team) {
                throw new InvalidWorkReportRequest(\sprintf('Unknown team "%s", teams are given by id.', $value), 404);
            }
            if (!$viewer->canSeeAllData() && !$viewer->isTeamleadOf($team)) {
                throw new InvalidWorkReportRequest(\sprintf('You do not lead team "%s".', $team->getName()), 403);
            }
            $result[$team->getId()] = $team;
        }

        return array_values($result);
    }

    /**
     * @param array<string> $values
     * @return array<\App\Entity\Tag>
     */
    private function resolveTags(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $tag = ctype_digit($value) ? $this->tags->find((int) $value) : $this->tags->findTagByName($value);
            if ($tag === null) {
                throw new InvalidWorkReportRequest(\sprintf('Unknown tag "%s".', $value), 404);
            }
            $result[$tag->getId()] = $tag;
        }

        return array_values($result);
    }

    /**
     * @template T of object
     * @param array<string> $values
     * @param callable(int): ?T $finder
     * @return array<T>
     */
    private function resolve(array $values, string $label, callable $finder): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!ctype_digit($value)) {
                throw new InvalidWorkReportRequest(\sprintf('A %s is given by its numeric id, got "%s".', $label, $value));
            }
            $item = $finder((int) $value);
            if ($item === null) {
                throw new InvalidWorkReportRequest(\sprintf('Unknown %s %s.', $label, $value), 404);
            }
            $result[(int) $value] = $item;
        }

        return array_values($result);
    }

    private function bool(string $value, string $field): ?bool
    {
        return match (strtolower(trim($value))) {
            '' , 'all' => null,
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => throw new InvalidWorkReportRequest(\sprintf('"%s" must be 1 or 0, got "%s".', $field, $value)),
        };
    }
}
