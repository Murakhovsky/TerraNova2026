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
    public const WEB_SEARCH = 'web.search';
    public const WEB_COMMANDS = 'web.commands';
    public const WEB_WORKSPACE = 'web.workspace';
    public const WEB_WORKSPACE_EXTENSIONS = 'web.workspace.extensions';
    public const WEB_DASHBOARD_WIDGETS = 'web.dashboard_widgets';
    public const WEB_ENTITY_LINKS = 'web.entity_links';
    public const WEB_NOTIFICATIONS = 'web.notifications';
    public const WEB_ACTIVITY = 'web.activity';
    public const WEB_ACTIONS = 'web.actions';

    private function __construct()
    {
    }
}
