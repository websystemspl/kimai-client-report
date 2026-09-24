<?php

namespace KimaiPlugin\ClientReportBundle\Report;

use App\Mail\KimaiMailer;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
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

        // the mail goes out while the client is browsing, so the request locale is
        // theirs - the creator's own UI language is the one to write in
        $language = $creator->getLanguage();
        $t = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, 'client_report', $language);

        $body = implode("\n", [
            $t('mail.intro'),
            '',
            \sprintf('%s: %s', $t('mail.scope'), $scope),
            \sprintf('%s: %s', $t('mail.period'), $period),
            \sprintf('%s: %s', $t('mail.link'), $url),
            '',
            $t('mail.footer'),
        ]);

        $email = (new Email())
            ->subject($t('mail.subject', ['%scope%' => $scope, '%period%' => $period]))
            ->text($body);

        try {
            $this->mailer->sendToUser($creator, $email);
        } catch (\Throwable $exception) {
            // the client is waiting for a page - a broken mail relay must not turn
            // their report into an error page
            $this->logger->error('ClientReport: could not send the first view notification', [
                'report' => $report->getId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
