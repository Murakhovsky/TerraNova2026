<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Property\Application\Contract\PropertyFunnelAnalyticsInterface;
use Domains\Identity\Application\Contract\AuthenticatedUserContextInterface;
use Domains\Identity\Application\Contract\TelegramAccountLinkInterface;
use Interfaces\Web\Service\ClientCaseService;
use Domains\Content\Application\Contract\ContentServiceInterface;
use Interfaces\Web\Service\InboundRequestService;
use Domains\Property\Application\Contract\PropertyManagementInterface;
use Domains\Property\Application\Contract\PropertyModerationInterface;
use Domains\Property\Application\Contract\PropertyPresentationInterface;
use Domains\Property\Application\Contract\PropertySubmissionInterface;
use Interfaces\Web\Page\PublicPageService;
use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Kernel\Operations\Contract\NotificationOperationsReadModelInterface;
use Phalcon\Mvc\Controller;
use Throwable;

class ControllerBase extends Controller
{
    protected function catalogService(): PropertyCatalogInterface
    {
        return $this->di->getShared('frontendCatalogService');
    }

    protected function analyticsService(): PropertyFunnelAnalyticsInterface
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

    protected function contentService(): ContentServiceInterface
    {
        return $this->di->getShared('frontendContentService');
    }

    protected function spatialSceneService(): SpatialSceneInterface
    {
        return $this->di->getShared('spatialSceneService');
    }

    protected function authService(): AuthenticatedUserContextInterface
    {
        return $this->di->getShared('authService');
    }

    protected function telegramAutomationService(): TelegramAccountLinkInterface
    {
        return $this->di->getShared('telegramAutomationService');
    }

    protected function notificationOperations(): NotificationOperationsReadModelInterface
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

    protected function propertySubmissionService(): PropertySubmissionInterface
    {
        return $this->di->getShared('frontendPropertySubmissionService');
    }

    protected function propertyModerationService(): PropertyModerationInterface
    {
        return $this->di->getShared('frontendPropertyModerationService');
    }

    protected function propertyMediaService(): PropertyManagementInterface
    {
        return $this->di->getShared('frontendPropertyMediaService');
    }

    protected function propertyPresentationService(): PropertyPresentationInterface
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
        if (($payload['ok'] ?? null) === false && !isset($payload['error'])) {
            $payload['error'] = $statusCode === 404 ? 'not_found' : ($statusCode === 422 ? 'validation_failed' : 'request_failed');
        }
        $this->view->disable();
        $this->response->setStatusCode($statusCode);
        $this->response->setContentType('application/json', 'UTF-8');
        $this->response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->response;
    }
}

