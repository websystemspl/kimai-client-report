<?php

namespace KimaiPlugin\ClientReportBundle\Controller\Api;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;
use KimaiPlugin\ClientReportBundle\Report\Labels;
use KimaiPlugin\ClientReportBundle\Report\ReportBuilder;
use KimaiPlugin\ClientReportBundle\Report\SharedReportManager;
use KimaiPlugin\ClientReportBundle\Repository\SharedReportRepository;
use KimaiPlugin\ClientReportBundle\WorkReport\WorkReportBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Client links over the API: create, list, inspect, revoke, restore, delete, and
 * read the exact numbers the client will see before sending the link.
 * Same permission as the panel (teamlead and up).
 */
#[Route(path: '/client-reports')]
#[IsGranted('ROLE_TEAMLEAD')]
final class ClientReportApiController extends AbstractController
{
    public function __construct(
        private readonly SharedReportRepository $reports,
        private readonly SharedReportManager $manager,
        private readonly ReportBuilder $builder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'client_report_api_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $state = (string) $request->query->get('state', '');
        if (!\in_array($state, ['', 'active', 'revoked', 'expired'], true)) {
            return ApiJson::error('"state" must be one of: active, revoked, expired.');
        }
        $customer = $request->query->getInt('customer');
        $project = $request->query->getInt('project');

        $result = [];
        foreach ($this->reports->findAllForList() as $report) {
            if ($customer > 0 && ($report->getCustomer()?->getId() ?? $report->getProject()?->getCustomer()?->getId()) !== $customer) {
                continue;
            }
            if ($project > 0 && $report->getProject()?->getId() !== $project) {
                continue;
            }
            if ($state !== '' && $this->state($report) !== $state) {
                continue;
            }
            $result[] = $this->normalize($report);
        }

        return ApiJson::ok($result);
    }

    #[Route(path: '', name: 'client_report_api_create', methods: ['POST'])]
    public function create(
        Request $request,
        CustomerRepository $customers,
        ProjectRepository $projects,
        UserRepository $users,
        ActivityRepository $activities,
        TagRepository $tags,
    ): Response {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return ApiJson::error('The body must be a JSON object.');
        }

        $known = ['customer', 'project', 'begin', 'end', 'locale', 'showNonBillable', 'title', 'expiresAt', 'users', 'activities', 'tags', 'excludedProjects'];
        $unknown = array_diff(array_keys($data), $known);
        if ($unknown !== []) {
            return ApiJson::error(\sprintf('Unknown field(s): %s. Allowed: %s.', implode(', ', $unknown), implode(', ', $known)));
        }

        try {
            $report = new SharedReport();

            if (isset($data['customer']) && isset($data['project'])) {
                throw new \InvalidArgumentException('Pass either "customer" or "project", not both.');
            }
            if (isset($data['project'])) {
                $report->setProject($this->entity($projects, $data['project'], 'project'));
            }
            if (isset($data['customer'])) {
                $report->setCustomer($this->entity($customers, $data['customer'], 'customer'));
            }

            $report->setDateStart($this->date($data['begin'] ?? null, 'begin'));
            $report->setDateEnd($this->date($data['end'] ?? null, 'end'));

            if (isset($data['expiresAt'])) {
                $report->setExpiresAt($this->date($data['expiresAt'], 'expiresAt'));
            }

            $locale = $data['locale'] ?? 'en';
            if (!\in_array($locale, Labels::available(), true)) {
                throw new \InvalidArgumentException(\sprintf('"locale" must be one of: %s.', implode(', ', Labels::available())));
            }
            $report->setLocale($locale);

            if (\array_key_exists('showNonBillable', $data)) {
                if (!\is_bool($data['showNonBillable'])) {
                    throw new \InvalidArgumentException('"showNonBillable" must be true or false.');
                }
                $report->setShowNonBillable($data['showNonBillable']);
            }

            if (isset($data['title'])) {
                if (!\is_string($data['title']) || mb_strlen($data['title']) > 255) {
                    throw new \InvalidArgumentException('"title" must be a string of at most 255 characters.');
                }
                $report->setTitle($data['title'] === '' ? null : $data['title']);
            }

            foreach ($this->ids($data, 'users') as $id) {
                $report->addUser($this->entity($users, $id, 'user'));
            }
            foreach ($this->ids($data, 'activities') as $id) {
                $report->addActivity($this->entity($activities, $id, 'activity'));
            }
            foreach ($this->ids($data, 'tags') as $id) {
                $report->addTag($this->entity($tags, $id, 'tag'));
            }
            foreach ($this->ids($data, 'excludedProjects') as $id) {
                $report->addExcludedProject($this->entity($projects, $id, 'project'));
            }
        } catch (\InvalidArgumentException $e) {
            return ApiJson::error($e->getMessage(), $e->getCode() === 404 ? 404 : 400);
        }

        $problem = $this->manager->validate($report);
        if ($problem !== null) {
            return ApiJson::error($this->translator->trans($problem, [], 'client_report', 'en'));
        }

        $user = $this->getUser();
        $this->manager->create($report, $user instanceof User ? $user : null);

        return ApiJson::ok($this->normalize($report), Response::HTTP_CREATED);
    }

    #[Route(path: '/{id}', name: 'client_report_api_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id): Response
    {
        $report = $this->reports->find($id);

        return $report === null ? $this->notFound($id) : ApiJson::ok($this->normalize($report));
    }

    /**
     * The numbers exactly as the client will see them, so whoever sends the link
     * (a person or an agent) can check them first. Works for revoked links too.
     */
    #[Route(path: '/{id}/data', name: 'client_report_api_data', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function data(int $id): Response
    {
        $report = $this->reports->find($id);
        if ($report === null) {
            return $this->notFound($id);
        }

        $data = $this->builder->build($report);
        $entries = [];
        foreach ($data->entries as $entry) {
            $entries[] = [
                'id' => $entry->getId(),
                'begin' => $entry->getBegin()?->format(\DATE_ATOM),
                'end' => $entry->getEnd()?->format(\DATE_ATOM),
                'seconds' => (int) $entry->getDuration(false),
                'hm' => WorkReportBuilder::hm((int) $entry->getDuration(false)),
                'user' => $entry->getUser()?->getDisplayName(),
                'project' => $entry->getProject()?->getName(),
                'activity' => $entry->getActivity()?->getName(),
                'description' => (string) $entry->getDescription(),
                'billable' => $entry->isBillable(),
            ];
        }

        $hm = static fn (array $list): array => array_map(static fn (int $s): array => ['seconds' => $s, 'hm' => WorkReportBuilder::hm($s)], $list);

        return ApiJson::ok([
            'report' => $this->normalize($report),
            'totals' => [
                'seconds' => $data->totalSeconds,
                'hm' => WorkReportBuilder::hm($data->totalSeconds),
                'billableSeconds' => $data->billableSeconds,
                'billableHm' => WorkReportBuilder::hm($data->billableSeconds),
                'nonBillableSeconds' => $data->getNonBillableSeconds(),
                'nonBillableHm' => WorkReportBuilder::hm($data->getNonBillableSeconds()),
                'billablePercent' => $data->getBillablePercent(),
                'entries' => $data->entryCount,
                'people' => $data->peopleCount,
                'days' => $data->dayCount,
                'averageDailyBillableSeconds' => $data->getAverageDailySeconds(),
            ],
            'billableByPerson' => $hm($data->perPerson),
            'billableByActivity' => $hm($data->perActivity),
            'entriesShown' => \count($entries),
            'entries' => $entries,
        ]);
    }

    /**
     * Revokes the link (the client gets "no longer active"). The row stays, as in
     * the panel. Add ?purge=1 to delete it for good.
     */
    #[Route(path: '/{id}', name: 'client_report_api_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, Request $request): Response
    {
        $report = $this->reports->find($id);
        if ($report === null) {
            return $this->notFound($id);
        }

        if ($request->query->getBoolean('purge')) {
            $this->reports->remove($report);

            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        $report->revoke();
        $this->reports->save($report);

        return ApiJson::ok($this->normalize($report));
    }

    #[Route(path: '/{id}/restore', name: 'client_report_api_restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(int $id): Response
    {
        $report = $this->reports->find($id);
        if ($report === null) {
            return $this->notFound($id);
        }

        $report->restore();
        $this->reports->save($report);

        return ApiJson::ok($this->normalize($report));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(SharedReport $report): array
    {
        $named = static fn (iterable $items): array => array_values(array_map(
            static fn ($item): array => ['id' => $item->getId(), 'name' => $item instanceof User ? $item->getDisplayName() : $item->getName()],
            \is_array($items) ? $items : iterator_to_array($items)
        ));
        $customer = $report->getCustomer() ?? $report->getProject()?->getCustomer();

        return [
            'id' => $report->getId(),
            'token' => $report->getToken(),
            'url' => $this->generateUrl('client_report_share', ['token' => $report->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            'pdfUrl' => $this->generateUrl('client_report_share_pdf', ['token' => $report->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            'title' => $report->getTitle(),
            'customer' => $customer !== null ? ['id' => $customer->getId(), 'name' => $customer->getName()] : null,
            'project' => $report->getProject() !== null ? ['id' => $report->getProject()->getId(), 'name' => $report->getProject()->getName()] : null,
            'scope' => $report->getProject() !== null ? 'project' : 'customer',
            'begin' => $report->getDateStart()?->format('Y-m-d'),
            'end' => $report->getDateEnd()?->format('Y-m-d'),
            'locale' => $report->getLocale(),
            'showNonBillable' => $report->isShowNonBillable(),
            'users' => $named($report->getUsers()),
            'activities' => $named($report->getActivities()),
            'tags' => $named($report->getTags()),
            'excludedProjects' => $named($report->getExcludedProjects()),
            'state' => $this->state($report),
            'expiresAt' => $report->getExpiresAt()?->format('Y-m-d'),
            'revokedAt' => $report->getRevokedAt()?->format(\DATE_ATOM),
            'views' => $report->getViews(),
            'lastViewAt' => $report->getLastViewAt()?->format(\DATE_ATOM),
            'createdAt' => $report->getCreatedAt()->format(\DATE_ATOM),
            'createdBy' => $report->getCreatedBy()?->getUserIdentifier(),
        ];
    }

    private function state(SharedReport $report): string
    {
        return match (true) {
            $report->isRevoked() => 'revoked',
            $report->isExpired() => 'expired',
            default => 'active',
        };
    }

    private function notFound(int $id): Response
    {
        return ApiJson::error(\sprintf('Client report %d not found.', $id), 404);
    }

    private function date(mixed $value, string $field): \DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is required, format YYYY-MM-DD.', $field));
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a date in the format YYYY-MM-DD, got "%s".', $field, $value));
        }

        return $date;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int>
     */
    private function ids(array $data, string $field): array
    {
        $value = $data[$field] ?? [];
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a list of ids.', $field));
        }
        foreach ($value as $id) {
            if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
                throw new \InvalidArgumentException(\sprintf('"%s" must be a list of numeric ids.', $field));
            }
        }

        return array_map('intval', $value);
    }

    /**
     * @template T of object
     * @param \Doctrine\Persistence\ObjectRepository<T> $repository
     * @return T
     */
    private function entity(object $repository, mixed $id, string $label): object
    {
        if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a numeric id.', $label));
        }
        $entity = $repository->find((int) $id);
        if ($entity === null) {
            throw new \InvalidArgumentException(\sprintf('Unknown %s %d.', $label, $id), 404);
        }

        return $entity;
    }
}
