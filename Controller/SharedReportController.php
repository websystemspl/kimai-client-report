<?php

namespace KimaiPlugin\ClientReportBundle\Controller;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;
use KimaiPlugin\ClientReportBundle\Form\SharedReportType;
use KimaiPlugin\ClientReportBundle\Repository\SharedReportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/client-report')]
#[IsGranted('ROLE_TEAMLEAD')]
final class SharedReportController extends AbstractController
{
    public function __construct(
        private readonly SharedReportRepository $reports,
        private readonly ProjectRepository $projects,
        private readonly CustomerRepository $customers,
        private readonly UserRepository $users,
        private readonly ActivityRepository $activities,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/', name: 'client_report_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@ClientReport/admin/index.html.twig', [
            'reports' => $this->reports->findAllForList(),
        ]);
    }

    #[Route(path: '/create', name: 'client_report_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $report = new SharedReport();
        $monday = new \DateTimeImmutable('monday this week');
        $report->setDateStart($monday);
        $report->setDateEnd($monday->modify('+6 days'));
        $this->prefill($report, $request);

        $form = $this->createForm(SharedReportType::class, $report);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($report->getProject() === null && $report->getCustomer() === null) {
                $form->get('project')->addError(new \Symfony\Component\Form\FormError($this->trans('error.no_scope')));
            } elseif ($report->getDateEnd() < $report->getDateStart()) {
                $form->get('dateEnd')->addError(new \Symfony\Component\Form\FormError($this->trans('error.end_before_start')));
            } else {
                // a project already carries its customer, keeping both would only
                // widen the report to everything that customer has
                if ($report->getProject() !== null) {
                    $report->setCustomer(null);
                }

                $user = $this->getUser();
                if ($user instanceof User) {
                    $report->setCreatedBy($user);
                }

                $this->reports->save($report);
                $this->addFlash('success', $this->trans('flash.created'));

                return $this->redirectToRoute('client_report_index');
            }
        }

        return $this->render('@ClientReport/admin/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // state changing actions carry a CSRF token in the path, the same way Kimai
    // does it for invoice and template deletion

    #[Route(path: '/{id}/revoke/{csrfToken}', name: 'client_report_revoke', methods: ['GET'])]
    public function revoke(SharedReport $report, string $csrfToken): Response
    {
        $this->denyOnInvalidCsrf($csrfToken);

        $report->revoke();
        $this->reports->save($report);
        $this->addFlash('success', $this->trans('flash.revoked'));

        return $this->redirectToRoute('client_report_index');
    }

    #[Route(path: '/{id}/restore/{csrfToken}', name: 'client_report_restore', methods: ['GET'])]
    public function restore(SharedReport $report, string $csrfToken): Response
    {
        $this->denyOnInvalidCsrf($csrfToken);

        $report->restore();
        $this->reports->save($report);
        $this->addFlash('success', $this->trans('flash.restored'));

        return $this->redirectToRoute('client_report_index');
    }

    #[Route(path: '/{id}/delete/{csrfToken}', name: 'client_report_delete', methods: ['GET'])]
    public function delete(SharedReport $report, string $csrfToken): Response
    {
        $this->denyOnInvalidCsrf($csrfToken);

        $this->reports->remove($report);
        $this->addFlash('success', $this->trans('flash.deleted'));

        return $this->redirectToRoute('client_report_index');
    }

    /**
     * Applies the "share with the client" prefill coming from Kimai's export screen.
     * Anything unparsable is ignored - the form keeps its own defaults.
     */
    private function prefill(SharedReport $report, Request $request): void
    {
        $begin = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->query->get('begin', ''));
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->query->get('end', ''));
        if ($begin !== false && $end !== false && $end >= $begin) {
            $report->setDateStart($begin);
            $report->setDateEnd($end);
        }

        $projectId = $request->query->getInt('project');
        if ($projectId > 0) {
            $report->setProject($this->projects->find($projectId));
        } else {
            $customerId = $request->query->getInt('customer');
            if ($customerId > 0) {
                $report->setCustomer($this->customers->find($customerId));
            }
        }

        if ($request->query->get('nonBillable') === '0') {
            $report->setShowNonBillable(false);
        }

        foreach ($this->idList($request, 'users') as $id) {
            $user = $this->users->find($id);
            if ($user !== null) {
                $report->addUser($user);
            }
        }

        foreach ($this->idList($request, 'activities') as $id) {
            $activity = $this->activities->find($id);
            if ($activity !== null) {
                $report->addActivity($activity);
            }
        }
    }

    /**
     * @return array<int>
     */
    private function idList(Request $request, string $key): array
    {
        $raw = (string) $request->query->get($key, '');
        if ($raw === '') {
            return [];
        }

        return array_map('intval', array_filter(explode(',', $raw), 'is_numeric'));
    }

    /**
     * Flashes are translated here, not in the layout: Kimai renders them with its own
     * "flashmessages" domain, which knows nothing about this plugin's keys.
     */
    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], 'client_report');
    }

    private function denyOnInvalidCsrf(string $csrfToken): void
    {
        if (!$this->isCsrfTokenValid('client_report', $csrfToken)) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
    }
}
