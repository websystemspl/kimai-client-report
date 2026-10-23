<?php

namespace KimaiPlugin\ClientReportBundle\WorkReport;

use App\Pdf\HtmlToPdfConverter;
use KimaiPlugin\ClientReportBundle\Report\Branding;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders a built work report to PDF. Shared by the panel and the API, so the
 * downloaded file is the same whichever way it was asked for.
 */
final class WorkReportPdf
{
    public function __construct(
        private readonly Environment $twig,
        private readonly HtmlToPdfConverter $converter,
        private readonly TranslatorInterface $translator,
        private readonly Branding $branding,
    ) {
    }

    /**
     * @param array<string, mixed> $report
     */
    public function response(array $report, string $locale): Response
    {
        // the API has no locale in the URL, so the language comes from the user
        // profile and has to be forced on the translator for this one render
        $previous = null;
        if ($this->translator instanceof LocaleAwareInterface) {
            $previous = $this->translator->getLocale();
            $this->translator->setLocale($locale);
        }

        try {
            $html = $this->twig->render('@ClientReport/work/report.pdf.twig', [
                'r' => $report,
                'branding' => $this->branding,
                'locale' => $locale,
            ]);
        } finally {
            if ($previous !== null && $this->translator instanceof LocaleAwareInterface) {
                $this->translator->setLocale($previous);
            }
        }

        // a person-by-day grid wider than a week does not fit on a portrait page
        $landscape = $report['meta']['days'] > 7;
        $pdf = $this->converter->convertToPdf($html, [
            'format' => $landscape ? 'A4-L' : 'A4',
            'orientation' => $landscape ? 'L' : 'P',
            'setAutoTopMargin' => 'stretch',
        ]);

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s"', $this->filename($report)));

        return $response;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function filename(array $report): string
    {
        $who = 'team';
        $users = $report['meta']['filters']['users'];
        if (\count($users) === 1) {
            $who = $users[0]['username'];
        }

        $name = \sprintf('work-report-%s-%s-%s', $who, $report['meta']['begin'], $report['meta']['end']);

        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'work-report', '-') . '.pdf';
    }
}
