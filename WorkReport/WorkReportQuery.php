<?php

namespace KimaiPlugin\ClientReportBundle\WorkReport;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\User;

/**
 * A fully resolved request for a work report: dates already turned into the first
 * and last second of the period in one timezone, filters already turned into
 * entities. The panel, the PDF and the API all build one of these and hand it to
 * the same builder, so they cannot disagree about the numbers.
 */
final class WorkReportQuery
{
    /**
     * @param array<User> $users
     * @param array<Team> $teams
     * @param array<Customer> $customers
     * @param array<Project> $projects
     * @param array<Activity> $activities
     * @param array<Tag> $tags
     */
    public function __construct(
        public readonly \DateTimeImmutable $begin,
        public readonly \DateTimeImmutable $end,
        public readonly \DateTimeZone $timezone,
        public readonly ?string $period = null,
        public readonly array $users = [],
        public readonly array $teams = [],
        public readonly array $customers = [],
        public readonly array $projects = [],
        public readonly array $activities = [],
        public readonly array $tags = [],
        public readonly ?bool $billable = null,
        public readonly bool $includeEntries = true,
    ) {
    }

    /**
     * Canonical query string parameters, readable by WorkReportRequestParser.
     * Used for the PDF link and the period shortcuts, so a link always reproduces
     * exactly the report on screen.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        $params = [
            'begin' => $this->begin->format('Y-m-d'),
            'end' => $this->end->format('Y-m-d'),
        ];

        $ids = static fn (array $items): array => array_map(static fn ($item) => $item->getId(), $items);

        foreach ([
            'users' => $this->users,
            'teams' => $this->teams,
            'customers' => $this->customers,
            'projects' => $this->projects,
            'activities' => $this->activities,
            'tags' => $this->tags,
        ] as $key => $items) {
            if ($items !== []) {
                $params[$key] = $ids($items);
            }
        }

        if ($this->billable !== null) {
            $params['billable'] = $this->billable ? '1' : '0';
        }

        return $params;
    }

    /**
     * Same as toParams() but without the dates, for links that switch the period
     * and keep everything else.
     *
     * @return array<string, mixed>
     */
    public function filterParams(): array
    {
        $params = $this->toParams();
        unset($params['begin'], $params['end']);

        return $params;
    }

    public function getDayCount(): int
    {
        return (int) $this->begin->diff($this->end)->days + 1;
    }
}
