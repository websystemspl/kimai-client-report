<?php

namespace KimaiPlugin\ClientReportBundle\WorkReport;

/**
 * Raised for anything wrong with the request itself: a bad date, an unknown id,
 * a user the caller may not see. The API turns it into a JSON error with the given
 * status, the panel into an error message above the form.
 */
final class InvalidWorkReportRequest extends \InvalidArgumentException
{
    public function __construct(string $message, private readonly int $status = 400)
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
