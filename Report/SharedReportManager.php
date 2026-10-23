<?php

namespace KimaiPlugin\ClientReportBundle\Report;

use App\Entity\User;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;
use KimaiPlugin\ClientReportBundle\Repository\SharedReportRepository;

/**
 * The one path for creating a client link, shared by the panel form and the API,
 * so both apply the same rules and cannot drift apart.
 */
final class SharedReportManager
{
    public function __construct(private readonly SharedReportRepository $reports)
    {
    }

    /**
     * @return string|null translation key (domain client_report) of the problem, null when valid
     */
    public function validate(SharedReport $report): ?string
    {
        if ($report->getProject() === null && $report->getCustomer() === null) {
            return 'error.no_scope';
        }

        if ($report->getDateStart() === null || $report->getDateEnd() === null) {
            return 'error.no_dates';
        }

        if ($report->getDateEnd() < $report->getDateStart()) {
            return 'error.end_before_start';
        }

        return null;
    }

    public function create(SharedReport $report, ?User $creator): void
    {
        // a project already carries its customer, keeping both would only widen the
        // report to everything that customer has
        if ($report->getProject() !== null) {
            $report->setCustomer(null);
        }

        if ($creator !== null) {
            $report->setCreatedBy($creator);
        }

        $this->reports->save($report);
    }
}
