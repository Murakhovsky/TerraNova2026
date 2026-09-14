<?php
declare(strict_types=1);

namespace Kernel\Module;

/**
 * Canonical names for extension points consumed directly by Kernel/bootstrap.
 * Domains may still declare additional extension points without central registration.
 */
final class ModuleExtensionPoint
{
    public const API_ROUTES = 'api.routes';
    public const TENANT_CONFIGURATION = 'tenant.configuration';
    public const EVENT_CONSUMERS = 'event.consumers';
    public const WEB_NAVIGATION = 'web.navigation';

    private function __construct()
    {
    }
}
