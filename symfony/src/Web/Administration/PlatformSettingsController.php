<?php
declare(strict_types=1);

namespace App\Web\Administration;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Llm\LlmModelCatalogInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;
use Platform\Settings\Contract\PlatformSettingsWriterInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PlatformSettingsController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private PlatformSettingsReaderInterface $settings,
        private PlatformSettingsWriterInterface $writer,
        private LlmModelCatalogInterface $modelCatalog,
        private string $fallbackProvider = 'http',
        private string $fallbackModel = '',
        private string $fallbackToken = '',
        private string $fallbackManagerModel = '',
        private string $fallbackArchitectModel = '',
        private string $fallbackDeveloperModel = '',
        private string $fallbackReviewerModel = '',
        private string $fallbackQaModel = '',
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        $organizationId = $tenant->organizationId()->value();
        $provider = (string) $this->settings->value($organizationId, 'llm', 'provider', $this->fallbackProvider);
        $model = (string) $this->settings->value($organizationId, 'llm', 'default_model', $this->fallbackModel);

        return new Response(
            $this->twig->render('experience/administration/settings/index.html.twig', [
                'shell' => $this->shell($tenant, 'Platform Settings', [
                    new ShellBreadcrumb('Workspace', '/admin'),
                    new ShellBreadcrumb('Administration'),
                    new ShellBreadcrumb('Settings'),
                ]),
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    ['PageHeader', 'Toolbar', 'EntityList', 'EmptyState', 'ErrorState'],
                    'normal',
                ),
                'summary' => [
                    'llm_provider' => $provider !== '' ? $provider : 'not configured',
                    'llm_model' => $model !== '' ? $model : 'provider default',
                ],
            ]),
            Response::HTTP_OK,
            $this->headers(),
        );
    }

    public function llm(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        $organizationId = $tenant->organizationId()->value();
        $secret = $this->settings->secret($organizationId, 'llm', 'openai.api_key', $this->fallbackToken);
        $data = [
            'provider' => (string) $this->settings->value($organizationId, 'llm', 'provider', $this->fallbackProvider),
            'default_model' => (string) $this->settings->value($organizationId, 'llm', 'default_model', $this->fallbackModel),
            'timeout_seconds' => (int) $this->settings->value($organizationId, 'llm', 'timeout_seconds', 90),
            'max_attempts' => (int) $this->settings->value($organizationId, 'llm', 'max_attempts', 3),
            'manager_model' => (string) $this->settings->value($organizationId, 'engineering', 'manager.model', $this->fallbackManagerModel),
            'architect_model' => (string) $this->settings->value($organizationId, 'engineering', 'architect.model', $this->fallbackArchitectModel),
            'developer_model' => (string) $this->settings->value($organizationId, 'engineering', 'developer.model', $this->fallbackDeveloperModel),
            'reviewer_model' => (string) $this->settings->value($organizationId, 'engineering', 'reviewer.model', $this->fallbackReviewerModel),
            'qa_model' => (string) $this->settings->value($organizationId, 'engineering', 'qa.model', $this->fallbackQaModel),
            'api_key_configured' => is_string($secret) && trim($secret) !== '',
            'api_key_suffix' => is_string($secret) && strlen($secret) >= 4 ? substr($secret, -4) : '',
            'model_catalog' => array_values($this->modelCatalog->models()),
            'pricing_catalog_version' => $this->modelCatalog->version(),
            'status_message' => trim((string) $request->query->get('status_message', '')),
            'status_error' => str_starts_with(trim((string) $request->query->get('status_message', '')), 'Помилка:'),
        ];

        return new Response(
            $this->twig->render('experience/administration/settings/llm.html.twig', [
                'shell' => $this->shell($tenant, 'LLM Settings', [
                    new ShellBreadcrumb('Workspace', '/admin'),
                    new ShellBreadcrumb('Settings', '/admin/settings'),
                    new ShellBreadcrumb('AI & LLM'),
                ]),
                'page' => $this->pages->create(
                    PageArchetype::FormEditor,
                    ['PageHeader', 'FormSection', 'StickyActions', 'ErrorState'],
                    'normal',
                ),
                'settings' => $data,
                'csrfToken' => $this->csrf->token($request),
            ]),
            Response::HTTP_OK,
            $this->headers(),
        );
    }

    public function saveLlm(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);

        $organizationId = $tenant->organizationId()->value();
        $actorId = (string) $tenant->userId()->value();
        $correlationId = trim((string) $request->headers->get('X-Correlation-ID', ''));
        if ($correlationId === '') $correlationId = bin2hex(random_bytes(16));

        try {
            $provider = strtolower(trim((string) $request->request->get('provider', 'openai')));
            if (!in_array($provider, ['openai','http','fixture'], true)) {
                throw new \InvalidArgumentException('Unsupported LLM provider.');
            }

            $values = [
                ['llm','provider',$provider,'string'],
                ['llm','default_model',trim((string) $request->request->get('default_model', '')),'string'],
                ['llm','timeout_seconds',max(1, min(600, (int) $request->request->get('timeout_seconds', 90))),'int'],
                ['llm','max_attempts',max(1, min(10, (int) $request->request->get('max_attempts', 3))),'int'],
                ['engineering','manager.model',trim((string) $request->request->get('manager_model', '')),'string'],
                ['engineering','architect.model',trim((string) $request->request->get('architect_model', '')),'string'],
                ['engineering','developer.model',trim((string) $request->request->get('developer_model', '')),'string'],
                ['engineering','reviewer.model',trim((string) $request->request->get('reviewer_model', '')),'string'],
                ['engineering','qa.model',trim((string) $request->request->get('qa_model', '')),'string'],
            ];

            foreach ($values as [$namespace,$key,$value,$type]) {
                if (is_string($value) && $value === '' && (
                    $namespace === 'engineering'
                    || ($namespace === 'llm' && $key === 'default_model')
                )) {
                    $this->writer->delete($organizationId, $namespace, $key, $actorId, $correlationId);
                    continue;
                }
                $this->writer->put($organizationId, $namespace, $key, $value, $type, $actorId, $correlationId);
            }

            $removeApiKey = $request->request->getBoolean('remove_openai_api_key');
            if ($removeApiKey) {
                $this->writer->deleteSecret($organizationId, 'llm', 'openai.api_key', $actorId, $correlationId);
            }

            $apiKey = trim((string) $request->request->get('openai_api_key', ''));
            if (!$removeApiKey && $apiKey !== '') {
                $this->writer->putSecret(
                    $organizationId,
                    'llm',
                    'openai.api_key',
                    $apiKey,
                    $actorId,
                    $correlationId,
                );
            }

            return $this->redirect('Налаштування LLM збережено.');
        } catch (Throwable $error) {
            error_log('platform.settings.llm.save_failed '.$error->getMessage());
            return $this->redirect('Помилка: '.$error->getMessage());
        }
    }

    private function admin(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isAdmin()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    /** @param list<ShellBreadcrumb> $breadcrumbs */
    private function shell(TenantContext $tenant, string $title, array $breadcrumbs): object
    {
        return $this->shells->create(
            $tenant,
            new WebExtensionContext(
                organizationId: $tenant->organizationId()->value(),
                role: $tenant->role()->value(),
                surface: 'system',
                activeSection: 'administration',
                activeItem: 'settings',
            ),
            $title,
            $breadcrumbs,
        );
    }

    private function redirect(string $message): RedirectResponse
    {
        return new RedirectResponse('/admin/settings/llm?status_message='.rawurlencode($message), Response::HTTP_SEE_OTHER);
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
