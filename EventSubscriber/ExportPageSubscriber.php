<?php

namespace KimaiPlugin\ClientReportBundle\EventSubscriber;

use App\Configuration\LocaleService;
use App\Event\ThemeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Puts a "share with the client" button on Kimai's own export screen and carries the
 * filters set there into the link form.
 *
 * It hooks the generic CONTENT_START theme event rather than PageActionsEvent, because
 * the export controller never sets an action name, so no page-actions event is fired
 * for that page. Overriding the template was the alternative - it lives in Kimai's
 * repository and the patch would be lost on the next update.
 */
final class ExportPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $router,
        private readonly AuthorizationCheckerInterface $security,
        private readonly LocaleService $localeService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ThemeEvent::CONTENT_START => ['onContentStart', 100]];
    }

    public function onContentStart(ThemeEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || $request->attributes->get('_route') !== 'export') {
            return;
        }

        if (!$this->security->isGranted('ROLE_TEAMLEAD')) {
            return;
        }

        $url = $this->router->generate('client_report_create', $this->prefill($request));

        $event->addContent(\sprintf(
            '<div class="mb-3 text-end"><a href="%s" class="btn btn-primary">%s</a></div>',
            htmlspecialchars($url, \ENT_QUOTES),
            htmlspecialchars($this->translator->trans('export.share_button', [], 'client_report'), \ENT_QUOTES)
        ));
    }

    /**
     * Translates the export toolbar's query string into the link form's prefill.
     *
     * @return array<string, string|int>
     */
    private function prefill(Request $request): array
    {
        $params = [];

        [$begin, $end] = $this->parseDateRange((string) $request->query->get('daterange', ''), $request->getLocale());
        if ($begin !== null && $end !== null) {
            $params['begin'] = $begin;
            $params['end'] = $end;
        }

        $projects = $request->query->all('projects');
        if (\count($projects) > 0 && is_numeric($projects[0])) {
            $params['project'] = (int) $projects[0];
        } else {
            $customers = $request->query->all('customers');
            if (\count($customers) > 0 && is_numeric($customers[0])) {
                $params['customer'] = (int) $customers[0];
            }
        }

        // export toolbar: 1 = billable only, 2 = not billable, 0 = all
        if ($request->query->get('billable') === '1') {
            $params['nonBillable'] = 0;
        }

        $users = $request->query->all('users');
        $userIds = array_values(array_filter($users, 'is_numeric'));
        if (\count($userIds) > 0) {
            $params['users'] = implode(',', $userIds);
        }

        $activities = $request->query->all('activities');
        $activityIds = array_values(array_filter($activities, 'is_numeric'));
        if (\count($activityIds) > 0) {
            $params['activities'] = implode(',', $activityIds);
        }

        return $params;
    }

    /**
     * The toolbar renders dates in the request locale's format, so that format is tried
     * first. The fallbacks only matter if the locale configuration ever changes shape;
     * each candidate has to round-trip exactly, so a format that merely happens to parse
     * is rejected.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseDateRange(string $range, string $locale): array
    {
        $parts = explode(' - ', $range);
        if (\count($parts) !== 2) {
            return [null, null];
        }

        $formats = [$this->localeService->getDateFormat($locale), 'd.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'];

        $dates = [];
        foreach ($parts as $part) {
            $iso = $this->parseDate(trim($part), $formats);
            if ($iso === null) {
                return [null, null];
            }
            $dates[] = $iso;
        }

        return [$dates[0], $dates[1]];
    }

    /**
     * @param array<string> $formats
     */
    private function parseDate(string $value, array $formats): ?string
    {
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
