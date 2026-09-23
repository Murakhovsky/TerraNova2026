<?php
declare(strict_types=1);

namespace App\Web\Workspace;

use App\Security\SessionCsrfValidator;
use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Identity\Application\Contract\AdministrationServiceInterface;
use Domains\Property\Application\Contract\PropertyFunnelAnalyticsInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class CoreWorkspacePageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private AdministrationServiceInterface $administration,
        private PropertyFunnelAnalyticsInterface $analytics,
        private SessionCsrfValidator $csrf,
    ) {
    }

    public function users(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        $filters = $this->administration->userFilters($request->query->all());
        try {
            return $this->render($request, $tenant, 'Користувачі та ролі', 'administration', 'users', 'admin/users', [
                'filters' => $filters,
                'users' => $this->administration->users($filters),
                'userStats' => $this->administration->userStats(),
                'roleCapabilities' => self::roleCapabilities(),
                'pageStatus' => null,
                'actionStatus' => (string) $request->query->get('status_message', ''),
                'csrfToken' => $this->csrf->token($request),
            ]);
        } catch (Throwable $error) {
            error_log('workspace.users.read_failed ' . $error->getMessage());
            return $this->render($request, $tenant, 'Користувачі та ролі', 'administration', 'users', 'admin/users', [
                'filters' => $filters,
                'users' => [],
                'userStats' => [],
                'roleCapabilities' => self::roleCapabilities(),
                'pageStatus' => 'Керування користувачами тимчасово недоступне.',
                'actionStatus' => '',
                'csrfToken' => $this->csrf->token($request),
            ], [], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function createUser(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);

        $result = $this->administration->createUser($request->request->all());
        return new RedirectResponse('/admin/users?status_message=' . rawurlencode((string) ($result['message'] ?? 'Користувача оброблено.')));
    }

    public function updateUser(Request $request, string $id): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);

        $result = $this->administration->updateUser((int) $id, $request->request->all(), [
            'id' => (int) $tenant->userId()->value(),
            'role' => $tenant->role()->value(),
        ]);
        return new RedirectResponse('/admin/users?status_message=' . rawurlencode((string) ($result['message'] ?? 'Користувача оновлено.')));
    }

    public function analytics(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        $days = max(7, min(365, (int) $request->query->get('days', 30)));

        try {
            return $this->render($request, $tenant, 'Аналітика', 'analytics', 'analytics', 'admin/analytics', [
                'report' => $this->analytics->report($days),
                'pageStatus' => null,
            ], ['analytics-workspace']);
        } catch (Throwable $error) {
            error_log('workspace.analytics.read_failed ' . $error->getMessage());
            return $this->render($request, $tenant, 'Аналітика', 'analytics', 'analytics', 'admin/analytics', [
                'report' => [],
                'pageStatus' => 'Аналітика тимчасово недоступна. Деталі записано в лог.',
            ], ['analytics-workspace'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function admin(): TenantContext|Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$tenant->isAdmin()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    /** @param array<string,mixed> $extra @param list<string> $assets */
    private function render(Request $request, TenantContext $tenant, string $title, string $section, string $active, string $view, array $extra = [], array $assets = [], int $status = 200): Response
    {
        $role = $tenant->role()->value();
        $variables = array_replace([
            'title' => $title,
            'metaTitle' => $title . ' | Terra Nova COS',
            'metaRobots' => 'noindex,nofollow',
            'interfaceSurface' => 'workspace',
            'workspaceSection' => $section,
            'workspaceActive' => $active,
            'workspaceActiveSection' => $this->navigation->activeSection($active),
            'pageAssetEntries' => $assets,
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => true,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ], $extra);

        return new Response($this->renderer->render($request, $view, $variables), $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @return array<string,array<string,bool>> */
    private static function roleCapabilities(): array
    {
        return [
            'buyer' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>false,'listing'=>false,'crm'=>false,'admin'=>false],
            'seller' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>false,'crm'=>false,'admin'=>false],
            'investor' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>false,'listing'=>false,'crm'=>false,'admin'=>false],
            'realtor' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
            'developer' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
            'partner' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
            'manager' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>true,'admin'=>false],
            'admin' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>true,'admin'=>true],
        ];
    }
}
