<?php
declare(strict_types=1);

namespace App\Web\Content;

use App\Security\SessionCsrfValidator;
use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Content\Application\Contract\ContentServiceInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ContentAdminPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private NavigationBuilder $navigation,
        private ContentServiceInterface $content,
    ) {
    }

    public function manage(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $filters = $request->query->all();
        try {
            return $this->render($request, $tenant, 'content/manage', [
                'metaTitle' => 'Контент і SEO | Terra Nova CLUB',
                'items' => $this->content->adminItems($filters),
                'stats' => $this->content->stats(),
                'integrationStats' => $this->content->integrationStats(),
                'deliveries' => $this->content->recentWebhookDeliveries(),
                'filters' => $filters,
                'pageStatus' => null,
            ]);
        } catch (Throwable $error) {
            error_log(sprintf('content.admin.manage [%s] %s', $error::class, $error->getMessage()));
            return $this->render($request, $tenant, 'content/manage', [
                'metaTitle' => 'Контент і SEO | Terra Nova CLUB',
                'items' => [],
                'stats' => [],
                'integrationStats' => [],
                'deliveries' => [],
                'filters' => $filters,
                'pageStatus' => 'Контент тимчасово недоступний. Деталі записано в лог.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function edit(Request $request, string $id = '0'): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $contentId = ctype_digit($id) ? (int) $id : 0;
        try {
            $item = $contentId > 0 ? $this->content->item($contentId) : null;
            $status = $contentId > 0 && $item === null ? Response::HTTP_NOT_FOUND : Response::HTTP_OK;
            $type = (string) $request->query->get('type', 'blog_post');
            if (!in_array($type, ['blog_post', 'seo_landing'], true)) {
                $type = 'blog_post';
            }

            return $this->render($request, $tenant, 'content/edit', [
                'metaTitle' => ($item ? 'Редагування контенту' : 'Новий матеріал') . ' | Terra Nova CLUB',
                'item' => $item ?: [
                    'id' => 0,
                    'content_type' => $type,
                    'status' => 'draft',
                    'robots' => 'index,follow',
                    'body_html' => '',
                ],
                'revisions' => $contentId > 0 && $item !== null ? $this->content->revisions($contentId) : [],
                'actionStatus' => (string) $request->query->get('status_message', ''),
            ], $status);
        } catch (Throwable $error) {
            error_log(sprintf('content.admin.edit [%s] %s', $error::class, $error->getMessage()));
            return $this->failure($request, $tenant, Response::HTTP_SERVICE_UNAVAILABLE, 'Контент тимчасово недоступний.');
        }
    }

    public function save(Request $request, string $id = '0'): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }
        if (!$this->csrf->isValid($request)) {
            return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);
        }

        $input = $request->request->all();
        $routeId = ctype_digit($id) ? (int) $id : 0;
        $input['id'] = $routeId > 0 ? $routeId : (int) ($input['id'] ?? 0);

        $result = $this->content->save($input, [
            'id' => (int) $tenant->userId()->value(),
            'role' => $tenant->role()->value(),
            'organization_id' => $tenant->organizationId()->value(),
        ]);

        $target = !empty($result['id'])
            ? '/admin/content/edit/' . (int) $result['id']
            : '/admin/content/edit';

        return new RedirectResponse(
            $target . '?status_message=' . rawurlencode((string) ($result['message'] ?? '')),
            Response::HTTP_SEE_OTHER,
        );
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return $tenant;
    }

    /** @param array<string,mixed> $extra */
    private function render(
        Request $request,
        TenantContext $tenant,
        string $view,
        array $extra,
        int $status = Response::HTTP_OK,
    ): Response {
        $role = $tenant->role()->value();
        $variables = array_replace([
            'metaRobots' => 'noindex,nofollow',
            'interfaceSurface' => 'workspace',
            'workspaceSection' => 'administration',
            'workspaceActive' => 'content',
            'workspaceActiveSection' => $this->navigation->activeSection('content'),
            'pageAssetEntries' => [],
            'csrfToken' => $request->hasSession() ? (string) $request->getSession()->get('cos_csrf_token', '') : '',
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => true,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ], $extra);

        return new Response(
            $this->renderer->render($request, $view, $variables),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function failure(Request $request, TenantContext $tenant, int $status, string $message): Response
    {
        return $this->render($request, $tenant, 'error/failure', [
            'metaTitle' => 'Content Admin | Terra Nova',
            'failureCode' => $status,
            'failureTitle' => 'Сервіс тимчасово недоступний',
            'failureMessage' => $message,
            'failureRequestId' => 'TN-' . strtoupper(bin2hex(random_bytes(5))),
            'failureActionUrl' => '/admin/content',
            'failureActionLabel' => 'До контенту',
        ], $status);
    }
}
