<?php

namespace KimaiPlugin\ClientReportBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMainMenuEvent::class => ['onMenuConfigure', 100]];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        $reporting = $event->getReportingMenu();
        $parent = $reporting ?? $event->getMenu();

        // the work report is open to everyone who may see reports: people without
        // access to other users' times get their own time only
        if ($this->security->isGranted('view_reporting')) {
            $work = new MenuItemModel('work_report', 'menu.work_report', 'work_report', [], 'reporting');
            $work->setTranslationDomain('client_report');
            $work->setChildRoutes(['work_report_pdf']);
            $parent->addChild($work);
        }

        if (!$this->security->isGranted('ROLE_TEAMLEAD')) {
            return;
        }

        $item = new MenuItemModel(
            'client_report',
            'menu.title',
            'client_report_index',
            [],
            'export'
        );
        $item->setTranslationDomain('client_report');
        $item->setChildRoutes(['client_report_create']);

        $parent->addChild($item);
    }
}
