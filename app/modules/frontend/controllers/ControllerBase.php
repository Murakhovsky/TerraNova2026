<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use Modules\Frontend\Services\CatalogService;
use Modules\Frontend\Services\AnalyticsService;
use Common\Services\AuthService;
use Common\Services\TelegramAutomationService;
use Modules\Frontend\Services\ClientCaseService;
use Modules\Frontend\Services\ContentService;
use Modules\Frontend\Services\InboundRequestService;
use Modules\Frontend\Services\PropertyMediaService;
use Modules\Frontend\Services\PropertyModerationService;
use Modules\Frontend\Services\PropertyPresentationService;
use Modules\Frontend\Services\PropertySubmissionService;
use Modules\Frontend\Services\PublicPageService;
use Phalcon\Mvc\Controller;
use Throwable;

class ControllerBase extends Controller
{
    protected function catalogService(): CatalogService
    {
        return $this->di->getShared('frontendCatalogService');
    }

    protected function analyticsService(): AnalyticsService
    {
        return $this->di->getShared('frontendAnalyticsService');
    }

    protected function publicPageService(): PublicPageService
    {
        return $this->di->getShared('frontendPublicPageService');
    }

    protected function clientCaseService(): ClientCaseService
    {
        return $this->di->getShared('frontendClientCaseService');
    }

    protected function contentService(): ContentService
    {
        return $this->di->getShared('frontendContentService');
    }

    protected function authService(): AuthService
    {
        return $this->di->getShared('authService');
    }

    protected function telegramAutomationService(): TelegramAutomationService
    {
        return $this->di->getShared('telegramAutomationService');
    }

    protected function currentUser(): ?array
    {
        return $this->authService()->currentUser();
    }

    protected function requireUser(): ?array
    {
        $user = $this->currentUser();

        if (!$user) {
            $this->response->redirect('auth/login');
            return null;
        }

        return $user;
    }

    protected function requireManager(): ?array
    {
        $user = $this->requireUser();

        if (!$user) {
            return null;
        }

        if (!$this->authService()->isManager($user)) {
            $this->response->setStatusCode(403, 'Forbidden');
            $this->response->redirect('cabinet');
            return null;
        }

        return $user;
    }

    protected function requireListingUser(): ?array
    {
        $user = $this->requireUser();

        if (!$user) {
            return null;
        }

        if (!in_array((string) ($user['role'] ?? ''), ['admin', 'manager', 'realtor', 'partner', 'developer'], true)) {
            $this->response->setStatusCode(403, 'Forbidden');
            $this->response->redirect('cabinet');
            return null;
        }

        return $user;
    }

    protected function requireAdmin(): ?array
    {
        $user = $this->requireUser();

        if (!$user) {
            return null;
        }

        if (!$this->authService()->isAdmin($user)) {
            $this->response->setStatusCode(403, 'Forbidden');
            $this->response->redirect('cabinet');
            return null;
        }

        return $user;
    }

    protected function inboundRequestService(): InboundRequestService
    {
        return $this->di->getShared('frontendInboundRequestService');
    }

    protected function propertySubmissionService(): PropertySubmissionService
    {
        return $this->di->getShared('frontendPropertySubmissionService');
    }

    protected function propertyModerationService(): PropertyModerationService
    {
        return $this->di->getShared('frontendPropertyModerationService');
    }

    protected function propertyMediaService(): PropertyMediaService
    {
        return $this->di->getShared('frontendPropertyMediaService');
    }

    protected function propertyPresentationService(): PropertyPresentationService
    {
        return $this->di->getShared('frontendPropertyPresentationService');
    }

    protected function submitInboundRequest(): string
    {
        $result = $this->inboundRequestService()->submit(
            (array) $this->request->getPost(),
            $this->request->getURI()
        );

        return $result['message'];
    }

    protected function submitPropertySubmission(): string
    {
        $result = $this->propertySubmissionService()->submit(
            (array) $this->request->getPost(),
            $this->request->getURI(),
            $_FILES
        );

        return $result['message'];
    }

    protected function logFrontendError(string $label, Throwable $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $entry = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $label, $error->getMessage(), PHP_EOL);
        @file_put_contents($directory . '/frontend.log', $entry, FILE_APPEND);
    }

    protected function json(array $payload, int $statusCode = 200): \Phalcon\Http\ResponseInterface
    {
        $this->view->disable();
        $this->response->setStatusCode($statusCode);
        $this->response->setContentType('application/json', 'UTF-8');
        $this->response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->response;
    }
}
