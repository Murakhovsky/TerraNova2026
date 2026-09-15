<?php

declare(strict_types=1);

namespace Interfaces\Web\Controller\Concerns;

use Throwable;

trait RendersFrontendFailure
{
    /** @var array<int,array{reason:string,title:string,message:string}> */
    private const FRONTEND_FAILURES = [
        403 => [
            'reason' => 'Forbidden',
            'title' => 'Доступ обмежено',
            'message' => 'У вас немає доступу до цієї дії або сторінки.',
        ],
        404 => [
            'reason' => 'Not Found',
            'title' => 'Сторінку не знайдено',
            'message' => 'Адреса могла змінитися або ресурс більше недоступний.',
        ],
        422 => [
            'reason' => 'Unprocessable Entity',
            'title' => 'Перевірте введені дані',
            'message' => 'Деякі дані не вдалося прийняти. Перевірте поля та спробуйте ще раз.',
        ],
        500 => [
            'reason' => 'Internal Server Error',
            'title' => 'Сталася технічна помилка',
            'message' => 'Ми зафіксували проблему. Спробуйте оновити сторінку трохи пізніше.',
        ],
        503 => [
            'reason' => 'Service Unavailable',
            'title' => 'Сервіс тимчасово недоступний',
            'message' => 'Частина системи зараз недоступна. Спробуйте ще раз трохи пізніше.',
        ],
    ];

    protected function renderFrontendFailure(
        int $statusCode,
        ?string $message = null,
        string $surface = 'workspace',
        ?string $requestId = null,
    ): void {
        $definition = self::FRONTEND_FAILURES[$statusCode] ?? self::FRONTEND_FAILURES[500];
        $statusCode = isset(self::FRONTEND_FAILURES[$statusCode]) ? $statusCode : 500;
        $surface = in_array($surface, ['public', 'portal', 'workspace'], true) ? $surface : 'workspace';
        $requestId = $requestId ?: $this->frontendRequestId();

        $this->response->setStatusCode($statusCode, $definition['reason']);
        $this->view->title = $definition['title'];
        $this->view->metaTitle = $definition['title'] . ' | Terra Nova';
        $this->view->metaRobots = 'noindex,nofollow';
        $this->view->interfaceSurface = $surface;
        $this->view->pageAssetEntries = [];
        $this->view->workspaceSection = null;
        $this->view->workspaceActive = null;
        $this->view->failureCode = $statusCode;
        $this->view->failureTitle = $definition['title'];
        $this->view->failureMessage = trim((string) $message) !== '' ? $message : $definition['message'];
        $this->view->failureRequestId = $requestId;
        $this->view->failureActionUrl = match ($surface) {
            'workspace' => $this->url->get('admin'),
            'portal' => $this->url->get('cabinet'),
            default => $this->url->get(''),
        };
        $this->view->failureActionLabel = match ($surface) {
            'workspace' => 'До Workspace',
            'portal' => 'До Portal',
            default => 'На головну',
        };
        $this->view->pick('error/failure');
    }

    protected function renderFrontendException(
        Throwable $error,
        string $label,
        int $statusCode = 503,
        string $surface = 'workspace',
    ): void {
        $requestId = $this->frontendRequestId();
        $context = [
            'request_id' => $requestId,
            'exception' => $error::class,
            'error' => $error->getMessage(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        ];

        if ($this->di->has('cosLogger')) {
            $this->di->getShared('cosLogger')->log('error', $label, $context);
        } else {
            error_log(sprintf('%s [%s] %s', $label, $requestId, $error->getMessage()));
        }

        $this->renderFrontendFailure($statusCode, null, $surface, $requestId);
    }

    private function frontendRequestId(): string
    {
        try {
            return 'TN-' . strtoupper(bin2hex(random_bytes(5)));
        } catch (Throwable) {
            return 'TN-' . strtoupper(substr(hash('sha256', microtime(true) . ':' . mt_rand()), 0, 10));
        }
    }
}
