<?php

namespace KimaiPlugin\ClientReportBundle\Controller\Api;

use App\Entity\User;
use KimaiPlugin\ClientReportBundle\WorkReport\InvalidWorkReportRequest;
use KimaiPlugin\ClientReportBundle\WorkReport\WorkReportBuilder;
use KimaiPlugin\ClientReportBundle\WorkReport\WorkReportPdf;
use KimaiPlugin\ClientReportBundle\WorkReport\WorkReportRequestParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Work report over Kimai's API, authenticated like every other /api route
 * (Authorization: Bearer <token>). Takes the same query string as the panel page
 * and returns the very array the page is rendered from.
 */
#[Route(path: '/work-report')]
#[IsGranted('view_reporting')]
final class WorkReportApiController extends AbstractController
{
    public function __construct(
        private readonly WorkReportRequestParser $parser,
        private readonly WorkReportBuilder $builder,
        private readonly WorkReportPdf $pdf,
    ) {
    }

    #[Route(path: '', name: 'client_report_api_work_report', methods: ['GET'])]
    public function report(Request $request): Response
    {
        $viewer = $this->viewer();

        try {
            $query = $this->parser->parse($request, $viewer);
        } catch (InvalidWorkReportRequest $e) {
            return ApiJson::error($e->getMessage(), $e->getStatus());
        }

        $report = $this->builder->build($query, $viewer);
        $report['links'] = [
            'panel' => $this->generateUrl('work_report', $query->toParams() + ['_locale' => $viewer->getLanguage()], UrlGeneratorInterface::ABSOLUTE_URL),
            'pdf' => $this->generateUrl('client_report_api_work_report_pdf', $query->toParams() + ['timezone' => $query->timezone->getName()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        return ApiJson::ok($report);
    }

    #[Route(path: '/pdf', name: 'client_report_api_work_report_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        $viewer = $this->viewer();

        try {
            $query = $this->parser->parse($request, $viewer);
        } catch (InvalidWorkReportRequest $e) {
            return ApiJson::error($e->getMessage(), $e->getStatus());
        }

        $locale = (string) $request->query->get('locale', $viewer->getLanguage());

        return $this->pdf->response($this->builder->build($query, $viewer), \in_array($locale, ['en', 'pl'], true) ? $locale : 'en');
    }

    private function viewer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Need a user for API access');
        }

        return $user;
    }
}
