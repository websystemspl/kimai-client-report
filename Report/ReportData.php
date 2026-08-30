<?php

namespace KimaiPlugin\ClientReportBundle\Report;

use App\Entity\Timesheet;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;

final class ReportData
{
    /**
     * @param array<Timesheet> $entries
     * @param array<string, int> $perPerson billable seconds per person, highest first
     * @param array<string, int> $perActivity billable seconds per type of work, highest first
     */
    public function __construct(
        public readonly SharedReport $report,
        public readonly array $entries,
        public readonly int $totalSeconds,
        public readonly int $billableSeconds,
        public readonly int $entryCount,
        public readonly int $peopleCount,
        public readonly int $dayCount,
        public readonly array $perPerson,
        public readonly array $perActivity,
    ) {
    }

    public function getNonBillableSeconds(): int
    {
        return $this->totalSeconds - $this->billableSeconds;
    }

    public function hasNonBillable(): bool
    {
        return $this->getNonBillableSeconds() > 0;
    }

    public function getBillablePercent(): float
    {
        if ($this->totalSeconds === 0) {
            return 0.0;
        }

        return round(($this->billableSeconds / $this->totalSeconds) * 100, 2);
    }

    public function getAverageDailySeconds(): int
    {
        if ($this->dayCount === 0) {
            return 0;
        }

        return (int) round($this->billableSeconds / $this->dayCount);
    }
}
