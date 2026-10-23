<?php

namespace KimaiPlugin\ClientReportBundle\Controller;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\TagRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use KimaiPlugin\ClientReportBundle\WorkReport\InvalidWorkReportRequest;
use KimaiPlugin\ClientReportBundle\WorkReport\WorkReportBuilder;
use KimaiPlugin\ClientReportBundle\WorkReport\WorkReportPdf;
use KimaiPlugin\ClientReportBundle\WorkReport\WorkReportQuery;
use KimaiPlugin\ClientReportBundle\WorkReport\WorkReportRequestParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Internal work report in the panel: who worked on what and for how long, for one
 * person or the whole team, over a day, a week or any range. Same numbers as
 * /api/work-report, because both go through WorkReportBuilder.
 */
#[Route(path: '/work-report')]
#[IsGranted('view_reporting')]
final class WorkReportController extends AbstractController
{
    public function __construct(
        private readonly WorkReportRequestParser $parser,
        private readonly WorkReportBuilder $builder,
        private readonly WorkReportPdf $pdf,
    ) {
    }

    #[Route(path: '/', name: 'work_report', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $users,
        TeamRepository $teams,
        CustomerRepository $customers,
        ProjectRepository $projects,
        ActivityRepository $activities,
        TagRepository $tags,
    ): Response {
        $viewer = $this->viewer();
        $error = null;

        try {
            $query = $this->parser->parse($request, $viewer);
        } catch (InvalidWorkReportRequest $e) {
            $error = $e->getMessage();
            $query = $this->parser->defaults($viewer);
        }

        $report = $this->builder->build($query, $viewer);
        $seeOthers = $this->isGranted('view_other_timesheet');

        return $this->render('@ClientReport/work/index.html.twig', [
            'r' => $report,
            'query' => $query,
            'error' => $error,
            'presets' => $this->presets($query),
            'pdf_params' => $query->toParams(),
            'see_others' => $seeOthers,
            'options' => [
                'users' => $seeOthers ? $this->visibleUsers($users) : [],
                'teams' => $seeOthers ? $this->visibleTeams($teams, $viewer) : [],
                'customers' => $customers->findBy(['visible' => true], ['name' => 'ASC']),
                'projects' => $this->sortedProjects($projects),
                'activities' => $activities->findBy(['visible' => true], ['name' => 'ASC']),
                'tags' => $tags->findBy([], ['name' => 'ASC']),
            ],
            'selected' => $this->selectedIds($query),
        ]);
    }

    #[Route(path: '/pdf', name: 'work_report_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        $viewer = $this->viewer();

        try {
            $query = $this->parser->parse($request, $viewer);
        } catch (InvalidWorkReportRequest $e) {
            return new Response($e->getMessage(), $e->getStatus(), ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $this->pdf->response($this->builder->build($query, $viewer), $request->getLocale());
    }

    private function viewer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @return array<array{key: string, url: string, active: bool}>
     */
    private function presets(WorkReportQuery $query): array
    {
        $result = [];
        foreach (WorkReportRequestParser::PERIODS as $period) {
            $result[] = [
                'key' => $period,
                'url' => $this->generateUrl('work_report', ['period' => $period] + $query->filterParams()),
                'active' => $query->period === $period,
            ];
        }

        return $result;
    }

    /**
     * @return array<User>
     */
    private function visibleUsers(UserRepository $repository): array
    {
        $users = array_filter($repository->findAll(), fn (User $u): bool => $this->isGranted('access_user', $u));
        // active people first, then former ones, each alphabetically
        usort($users, static fn (User $a, User $b): int => [!$a->isEnabled(), mb_strtolower($a->getDisplayName())] <=> [!$b->isEnabled(), mb_strtolower($b->getDisplayName())]);

        return $users;
    }

    /**
     * @return array<\App\Entity\Team>
     */
    private function visibleTeams(TeamRepository $repository, User $viewer): array
    {
        return array_values(array_filter(
            $repository->findBy([], ['name' => 'ASC']),
            static fn ($team): bool => $viewer->canSeeAllData() || $viewer->isTeamleadOf($team)
        ));
    }

    /**
     * @return array<\App\Entity\Project>
     */
    private function sortedProjects(ProjectRepository $repository): array
    {
        $projects = $repository->findBy(['visible' => true]);
        usort($projects, static fn ($a, $b): int => [mb_strtolower((string) $a->getCustomer()?->getName()), mb_strtolower((string) $a->getName())]
            <=> [mb_strtolower((string) $b->getCustomer()?->getName()), mb_strtolower((string) $b->getName())]);

        return $projects;
    }

    /**
     * @return array<string, array<int, bool>>
     */
    private function selectedIds(WorkReportQuery $query): array
    {
        $ids = static function (array $items): array {
            $result = [];
            foreach ($items as $item) {
                $result[$item->getId()] = true;
            }

            return $result;
        };

        return [
            'users' => $ids($query->users),
            'teams' => $ids($query->teams),
            'customers' => $ids($query->customers),
            'projects' => $ids($query->projects),
            'activities' => $ids($query->activities),
            'tags' => $ids($query->tags),
        ];
    }
}
