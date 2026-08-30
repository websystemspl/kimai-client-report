<?php

namespace KimaiPlugin\ClientReportBundle\Controller;

use App\Pdf\HtmlToPdfConverter;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;
use KimaiPlugin\ClientReportBundle\Report\Branding;
use KimaiPlugin\ClientReportBundle\Report\Labels;
use KimaiPlugin\ClientReportBundle\Report\ReportBuilder;
use KimaiPlugin\ClientReportBundle\Report\ShareNotifier;
use KimaiPlugin\ClientReportBundle\Repository\SharedReportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The client facing side. These routes sit outside the /{_locale} prefix, which is
 * what keeps them out of the "ROLE_USER" rule in config/packages/security.yaml -
 * so no login is required and no Kimai account is needed.
 */
#[Route(path: '/share')]
final class ShareController extends AbstractController
{
    public function __construct(
        private readonly SharedReportRepository $reports,
        private readonly ReportBuilder $builder,
        private readonly HtmlToPdfConverter $pdfConverter,
        private readonly Branding $branding,
        private readonly ShareNotifier $notifier,
    ) {
    }

    #[Route(path: '/{token}', name: 'client_report_share', requirements: ['token' => '[0-9a-f]{32}'], methods: ['GET'])]
    public function show(string $token): Response
    {
        $report = $this->load($token);

        if ($report === null) {
            return $this->gone();
        }

        $firstView = $report->getViews() === 0;
        $report->registerView();
        $this->reports->save($report);

        if ($firstView) {
            $this->notifier->notifyFirstView(
                $report,
                $this->generateUrl('client_report_share', ['token' => $report->getToken()], UrlGeneratorInterface::ABSOLUTE_URL)
            );
        }

        return $this->render('@ClientReport/public/report.html.twig', $this->viewData($report));
    }

    #[Route(path: '/{token}/pdf', name: 'client_report_share_pdf', requirements: ['token' => '[0-9a-f]{32}'], methods: ['GET'])]
    public function pdf(string $token): Response
    {
        $report = $this->load($token);

        if ($report === null) {
            return $this->gone();
        }

        $html = $this->renderView('@ClientReport/public/report.pdf.twig', $this->viewData($report));
        $pdf = $this->pdfConverter->convertToPdf($html, ['setAutoTopMargin' => 'stretch']);

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', \sprintf(
            'attachment; filename="%s"',
            $this->filename($report)
        ));

        return $response;
    }

    private function load(string $token): ?SharedReport
    {
        $report = $this->reports->findByToken($token);

        if ($report === null || !$report->isAccessible()) {
            return null;
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(SharedReport $report): array
    {
        return [
            'data' => $this->builder->build($report),
            'report' => $report,
            't' => Labels::for($report->getLocale()),
            'branding' => $this->branding,
            'timezone' => $this->displayTimezone($report),
        ];
    }

    /**
     * Timezone for the "generated at" stamp. Individual entries carry their own and
     * are rendered with it; only this one line needs a fallback. The visitor is not
     * logged in, so the process default is UTC - the creator's timezone is a much
     * better guess at what the report is about.
     */
    private function displayTimezone(SharedReport $report): string
    {
        $creator = $report->getCreatedBy();

        if ($creator !== null && $creator->getTimezone() !== '') {
            return $creator->getTimezone();
        }

        return date_default_timezone_get();
    }

    private function gone(): Response
    {
        // 404 rather than 410: a revoked or mistyped token should look the same
        // from the outside, so the URL space cannot be probed for valid links
        $labels = Labels::for('en');

        return $this->render(
            '@ClientReport/public/gone.html.twig',
            ['t' => $labels, 'branding' => $this->branding],
            new Response('', Response::HTTP_NOT_FOUND)
        );
    }

    private function filename(SharedReport $report): string
    {
        $parts = array_filter([
            $report->getCustomerName(),
            $report->getScopeName(),
            $report->getDateStart()?->format('Y-m-d'),
            $report->getDateEnd()?->format('Y-m-d'),
        ]);

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', implode('-', $parts)) ?? 'report';

        return trim($name, '-') . '.pdf';
    }
}
