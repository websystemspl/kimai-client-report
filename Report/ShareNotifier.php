<?php

namespace KimaiPlugin\ClientReportBundle\Report;

use App\Mail\KimaiMailer;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;

/**
 * Tells the person who created a link that the client has opened it.
 *
 * Only the first view is reported. A client who keeps the tab open and refreshes it
 * would otherwise turn this into a spam machine, and "they have seen it" is the one
 * fact worth an email.
 */
final class ShareNotifier
{
    public function __construct(
        private readonly KimaiMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifyFirstView(SharedReport $report, string $url): void
    {
        $creator = $report->getCreatedBy();

        if ($creator === null || $creator->getEmail() === null || !$creator->isEnabled()) {
            return;
        }

        $scope = trim($report->getCustomerName() . ' - ' . $report->getScopeName(), ' -');
        $period = \sprintf(
            '%s - %s',
            $report->getDateStart()?->format('d.m.Y') ?? '?',
            $report->getDateEnd()?->format('d.m.Y') ?? '?'
        );

        $body = <<<TEXT
            Klient otworzył raport po raz pierwszy.

            Zakres:  {$scope}
            Okres:   {$period}
            Link:    {$url}

            Wiadomość wychodzi tylko przy pierwszym otwarciu. Kolejne wejścia widać
            w liczniku: Raportowanie > Raporty dla klientów.
            TEXT;

        $email = (new Email())
            ->subject(\sprintf('Klient otworzył raport: %s (%s)', $scope, $period))
            ->text($body);

        try {
            $this->mailer->sendToUser($creator, $email);
        } catch (\Throwable $exception) {
            // the client is waiting for a page - a broken mail relay must not turn
            // their report into an error page
            $this->logger->error('ClientReport: nie udało się wysłać powiadomienia o otwarciu raportu', [
                'report' => $report->getId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
