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

        $reporting = $event->getReportingMenu();
        if ($reporting !== null) {
            $reporting->addChild($item);

            return;
        }

        $event->getMenu()->addChild($item);
    }
}
