<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class FrontendRoutes
{
    /** @param list<string> $publicPageSlugs */
    public static function register(RouterInterface $router, array $publicPageSlugs): void
    {
        $router->setDefaultNamespace('Interfaces\\Web\\Controller');
        $router->setDefaultController('index');
        $router->setDefaultAction('index');

        self::add($router, 'add', '/sitemap.xml', 'Interfaces\\Web\\Controller', 'seo', 'sitemap');
        self::add($router, 'add', '/robots.txt', 'Interfaces\\Web\\Controller', 'seo', 'robots');
        self::add($router, 'add', '/analytics/track', 'Interfaces\\Web\\Controller', 'analytics', 'track');
        self::add($router, 'addPost', '/webhooks/n8n/content', 'Interfaces\\Web\\Controller', 'n8n_webhook', 'content');
        self::add($router, 'add', '/blog', 'Interfaces\\Web\\Controller', 'blog', 'index');
        self::add($router, 'add', '/blog/{slug:[a-z0-9-]+}', 'Interfaces\\Web\\Controller', 'blog', 'show');
        self::add($router, 'add', '/guide/{slug:[a-z0-9-]+}', 'Interfaces\\Web\\Controller', 'blog', 'landing');
        self::add($router, 'add', '/admin/content', 'Interfaces\\Web\\Controller', 'content', 'manage');
        self::add($router, 'add', '/admin/content/edit', 'Interfaces\\Web\\Controller', 'content', 'edit');
        $router->add('/admin/content/:action/:params', self::target('Interfaces\\Web\\Controller', 'content', 1) + ['params' => 2]);
        self::add($router, 'add', '/nerukhomist/{location:[a-z0-9-]+}/{type:[a-z0-9-]+}', 'Interfaces\\Web\\Controller', 'property', 'landing');

        foreach ($publicPageSlugs as $slug) {
            $router->add('/' . $slug, self::target('Interfaces\\Web\\Controller', 'page', 'show') + ['slug' => $slug]);
        }

        $router->add('/api/property/:action/:params', self::target('Interfaces\\Web\\Controller', 'api', 1) + ['params' => 2]);
        self::add($router, 'add', '/api/property/:action', 'Interfaces\\Web\\Controller', 'api', 1);
        $router->addGet('/api/v1/properties/{slug:[a-z0-9-]+}', self::target('Interfaces\\Web\\Controller', 'api', 'show') + ['params' => 1]);
        self::add($router, 'addGet', '/api/v1/properties/featured', 'Interfaces\\Web\\Controller', 'api', 'featured');
        self::add($router, 'addGet', '/api/v1/properties', 'Interfaces\\Web\\Controller', 'api', 'catalog');
        self::add($router, 'add', '/submit-property', 'Interfaces\\Web\\Controller', 'property', 'submit');
        self::add($router, 'add', '/property/create', 'Interfaces\\Web\\Controller', 'property', 'submit');
        $router->add('/property/:action/:params', self::target('Interfaces\\Web\\Controller', 'property', 1) + ['params' => 2]);
        self::add($router, 'add', '/property/:action', 'Interfaces\\Web\\Controller', 'property', 1);
        self::add($router, 'add', '/property', 'Interfaces\\Web\\Controller', 'property', 'catalog');
        $router->add('/client-case/:action/:params', self::target('Interfaces\\Web\\Controller', 'client_case', 1) + ['params' => 2]);
        self::add($router, 'add', '/client-case/:action', 'Interfaces\\Web\\Controller', 'client_case', 1);
        self::add($router, 'add', '/client-case', 'Interfaces\\Web\\Controller', 'client_case', 'index');
        self::add($router, 'addGet', '/sales/dashboard', 'Interfaces\\Web\\Controller', 'sales', 'dashboard');
        self::add($router, 'addGet', '/sales/pipeline', 'Interfaces\\Web\\Controller', 'sales', 'pipeline');
        self::add($router, 'addGet', '/sales/today', 'Interfaces\\Web\\Controller', 'sales', 'today');
        self::add($router, 'addGet', '/sales/leads', 'Interfaces\\Web\\Controller', 'sales', 'leads');
        self::add($router, 'addGet', '/sales/deals', 'Interfaces\\Web\\Controller', 'sales', 'deals');
        self::add($router, 'addGet', '/sales/deals/{id:[0-9]+}', 'Interfaces\\Web\\Controller', 'sales', 'deal');
        self::add($router, 'addGet', '/sales/director', 'Interfaces\\Web\\Controller', 'sales', 'director');
        self::add($router, 'addGet', '/sales/admin', 'Interfaces\\Web\\Controller', 'sales', 'admin');
        self::add($router, 'addGet', '/admin/diagnostics/methodology-studio', 'Interfaces\\Web\\Controller', 'methodology_studio', 'index');

        self::add($router, 'addPost', '/cos/action/{id:[a-f0-9]{32}}/execute', 'Interfaces\\Web\\Controller', 'cos', 'execute');
        self::add($router, 'addPost', '/cos/approval/{id:[a-f0-9]{32}}/approve', 'Interfaces\\Web\\Controller', 'cos', 'approve');
        self::add($router, 'addPost', '/cos/approval/{id:[a-f0-9]{32}}/reject', 'Interfaces\\Web\\Controller', 'cos', 'reject');
        $router->add('/cos', self::target('Interfaces\\Web\\Controller', 'company_os', 'index') + ['lang' => 'en']);
        self::add($router, 'add', '/cos/{lang:[a-z]{2}}', 'Interfaces\\Web\\Controller', 'company_os', 'index');
        self::add($router, 'add', '/cos/{lang:[a-z]{2}}/domains/{slug:[a-z0-9-]+}', 'Interfaces\\Web\\Controller', 'company_os', 'domain');
        self::add($router, 'add', '/cos/control-center', 'Interfaces\\Web\\Controller', 'cos', 'index');

        self::add($router, 'addPost', '/api/approvals/{id:[a-f0-9]{32}}/approve', 'Interfaces\\Api\\Controller', 'approval', 'approve');
        self::add($router, 'addPost', '/api/approvals/{id:[a-f0-9]{32}}/reject', 'Interfaces\\Api\\Controller', 'approval', 'reject');
        self::add($router, 'addGet', '/api/health', 'Interfaces\\Api\\Controller', 'health', 'index');
        self::add($router, 'addGet', '/api/admin/diagnostics/packs', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'packs');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'packs');
        self::add($router, 'addGet', '/api/admin/diagnostics/history', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'history');
        self::add($router, 'addGet', '/api/admin/diagnostics/runs', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'runs');
        self::add($router, 'addGet', '/api/admin/diagnostics/permissions', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'permissions');
        self::add($router, 'addGet', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'pack');
        self::add($router, 'addGet', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'versions');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/entities/{type:[a-z_]+}', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'entity');
        self::add($router, 'addGet', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/entities', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'entities');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/entities/{type:[a-z_]+}/{entity:[a-z0-9_.-]+}/delete', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'deleteEntity');
        self::add($router, 'addGet', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/scenarios', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'scenarios');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/scenarios', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'scenario');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/validate', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'validate');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/simulate', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'simulate');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/clone', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'clone');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/publish', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'publish');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/regression', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'regression');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/archive', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'archive');
        self::add($router, 'addPost', '/api/admin/diagnostics/packs/{id:[a-z0-9-]+}/versions/{version:[0-9.]+}/activate', 'Interfaces\\Api\\Controller', 'diagnostic_methodology', 'activate');
        self::add($router, 'addGet', '/api/sales/dashboard', 'Interfaces\\Api\\Controller', 'sales', 'dashboard');
        self::add($router, 'addGet', '/api/sales/leads', 'Interfaces\\Api\\Controller', 'sales', 'leads');
        self::add($router, 'addGet', '/api/sales/deals', 'Interfaces\\Api\\Controller', 'sales', 'deals');
        self::add($router, 'addGet', '/api/sales/deals/{id:[0-9]+}', 'Interfaces\\Api\\Controller', 'sales', 'deal');
        self::add($router, 'addGet', '/api/sales/deals/{id:[0-9]+}/timeline', 'Interfaces\\Api\\Controller', 'sales', 'timeline');
        self::add($router, 'addGet', '/api/sales/deals/{id:[0-9]+}/intelligence', 'Interfaces\\Api\\Controller', 'sales', 'intelligence');
        self::add($router, 'addPost', '/api/sales/deals/{id:[0-9]+}/stage', 'Interfaces\\Api\\Controller', 'sales', 'stage');
        self::add($router, 'addGet', '/api/sales/pipelines', 'Interfaces\\Api\\Controller', 'sales', 'pipelines');
        self::add($router, 'addGet', '/api/sales/today', 'Interfaces\\Api\\Controller', 'sales', 'today');
        self::add($router, 'addGet', '/api/sales/metrics', 'Interfaces\\Api\\Controller', 'sales', 'metrics');
        self::add($router, 'addPost', '/api/sales/actions/{id:[a-f0-9]{32}}/outcomes', 'Interfaces\\Api\\Controller', 'sales', 'recordOutcome');
        self::add($router, 'addGet', '/api/cos/actions', 'Interfaces\\Api\\Controller', 'cos_runtime', 'actions');
        self::add($router, 'addGet', '/api/cos/actions/{id:[a-f0-9]{32}}', 'Interfaces\\Api\\Controller', 'cos_runtime', 'action');
        self::add($router, 'addPost', '/api/cos/actions/{id:[a-f0-9]{32}}/execute', 'Interfaces\\Api\\Controller', 'cos_runtime', 'execute');
        self::add($router, 'addGet', '/api/cos/approvals', 'Interfaces\\Api\\Controller', 'cos_runtime', 'approvals');
        self::add($router, 'addPost', '/api/cos/approvals/{id:[a-f0-9]{32}}/approve', 'Interfaces\\Api\\Controller', 'cos_runtime', 'approve');
        self::add($router, 'addPost', '/api/cos/approvals/{id:[a-f0-9]{32}}/reject', 'Interfaces\\Api\\Controller', 'cos_runtime', 'reject');
        self::add($router, 'addGet', '/api/cos/agents', 'Interfaces\\Api\\Controller', 'cos_runtime', 'agents');
        self::add($router, 'addGet', '/api/cos/rules', 'Interfaces\\Api\\Controller', 'cos_runtime', 'rules');
        self::add($router, 'addGet', '/api/cos/events', 'Interfaces\\Api\\Controller', 'cos_runtime', 'events');
        self::add($router, 'addGet', '/api/cos/audit', 'Interfaces\\Api\\Controller', 'cos_runtime', 'audit');
        self::add($router, 'addPost', '/api/integrations/{organization:[a-zA-Z0-9_-]+}/crm/{provider:[a-zA-Z0-9_-]+}/webhook', 'Interfaces\\Api\\Controller', 'crm_webhook', 'receive');

        foreach (['economy', 'games', 'users'] as $deprecatedModule) {
            self::add($router, 'add', '/' . $deprecatedModule, 'Interfaces\\Web\\Controller', 'deprecated_module', 'gone');
            self::add($router, 'add', '/' . $deprecatedModule . '/{path:.*}', 'Interfaces\\Web\\Controller', 'deprecated_module', 'gone');
        }
    }

    private static function add(RouterInterface $router, string $method, string $pattern, string $namespace, string $controller, string|int $action): void
    {
        $router->{$method}($pattern, self::target($namespace, $controller, $action));
    }

    /** @return array{namespace:string,module:string,controller:string,action:string|int} */
    private static function target(string $namespace, string $controller, string|int $action): array
    {
        return ['namespace' => $namespace, 'module' => 'frontend', 'controller' => $controller, 'action' => $action];
    }
}
