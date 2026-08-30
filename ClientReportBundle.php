<?php

namespace KimaiPlugin\ClientReportBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Bundle::getName() and Bundle::getPath() are final and already return what
 * PluginInterface asks for, so there is nothing left to implement here.
 */
final class ClientReportBundle extends Bundle implements PluginInterface
{
}
