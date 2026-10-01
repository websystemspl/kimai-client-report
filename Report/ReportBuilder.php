<?php

namespace KimaiPlugin\ClientReportBundle\Report;

use App\Entity\Timesheet;
use App\Repository\Query\BaseQuery;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;

/**
 * Turns a SharedReport into everything the public page needs.
 *
 * Both totals are always calculated, even when unpaid entries are hidden from the
 * table, so the page can state "X of Y hours billed" rather than silently showing
 * a shorter list.
 */
final class ReportBuilder
{
    public function __construct(private readonly TimesheetRepository $repository)
    {
    }

    public function build(SharedReport $report): ReportData
    {
        $entries = $this->fetchEntries($report);

        $totalSeconds = 0;
        $billableSeconds = 0;
        $people = [];
        $days = [];
        $perPerson = [];
        $perActivity = [];

        foreach ($entries as $entry) {
            $duration = $entry->getDuration() ?? 0;
            $totalSeconds += $duration;

            if ($entry->isBillable()) {
                $billableSeconds += $duration;
            }

            $user = $entry->getUser();
            if ($user !== null) {
                $name = $user->getDisplayName();
                $people[$name] = true;
                $perPerson[$name] = ($perPerson[$name] ?? 0) + ($entry->isBillable() ? $duration : 0);
            }

            $activity = $entry->getActivity();
            if ($activity !== null && $entry->isBillable()) {
                $label = (string) $activity->getName();
                $perActivity[$label] = ($perActivity[$label] ?? 0) + $duration;
            }

            $begin = $entry->getBegin();
            if ($begin !== null) {
                $days[$begin->format('Y-m-d')] = true;
            }
        }

        // somebody whose whole week was unpaid would otherwise show up as "0:00"
        $perPerson = array_filter($perPerson, static fn (int $seconds): bool => $seconds > 0);
        $perActivity = array_filter($perActivity, static fn (int $seconds): bool => $seconds > 0);

        arsort($perPerson);
        arsort($perActivity);

        $visible = $entries;
        if (!$report->isShowNonBillable()) {
            $visible = array_values(array_filter($entries, static fn (Timesheet $t): bool => $t->isBillable()));
        }

        return new ReportData(
            $report,
            $visible,
            $totalSeconds,
            $billableSeconds,
            \count($entries),
            \count($people),
            \count($days),
            $perPerson,
            $perActivity,
        );
    }

    /**
     * @return array<Timesheet>
     */
    private function fetchEntries(SharedReport $report): array
    {
        $query = new TimesheetQuery();

        $begin = \DateTime::createFromImmutable($report->getDateStart()->setTime(0, 0, 0));
        $end = \DateTime::createFromImmutable($report->getDateEnd()->setTime(23, 59, 59));

        $query->setBegin($begin);
        $query->setEnd($end);
        // running entries have no duration yet and would show up as 0:00
        $query->setState(TimesheetQuery::STATE_STOPPED);
        $query->setExported(TimesheetQuery::STATE_ALL);
        $query->setOrderBy('begin');
        $query->setOrder(BaseQuery::ORDER_ASC);

        if ($report->getProject() !== null) {
            $query->addProject($report->getProject());
        } elseif ($report->getCustomer() !== null) {
            $query->addCustomer($report->getCustomer());
        }

        foreach ($report->getUsers() as $user) {
            $query->addUser($user);
        }

        if (\count($report->getActivities()) > 0) {
            $query->setActivities($report->getActivities()->toArray());
        }

        foreach ($report->getTags() as $tag) {
            $query->addTag($tag);
        }

        // no current user is set on purpose: the person who created the share already
        // decided what the client may see, and there is nobody logged in to check against
        $entries = $this->repository->getTimesheetsForQuery($query, true);

        // TimesheetQuery can only include projects, so exclusions are applied here
        $excluded = [];
        foreach ($report->getExcludedProjects() as $project) {
            $excluded[$project->getId()] = true;
        }
        if ($excluded === []) {
            return $entries;
        }

        return array_values(array_filter(
            $entries,
            static fn (Timesheet $t): bool => !isset($excluded[$t->getProject()?->getId()])
        ));
    }
}
