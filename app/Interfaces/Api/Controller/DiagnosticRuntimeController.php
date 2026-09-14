<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Interfaces\Web\Controller\WebController;
use Phalcon\Http\ResponseInterface;
use Throwable;

final class DiagnosticRuntimeController extends WebController
{
    public function startAction(): ResponseInterface
    {
        return $this->call(
            fn (DiagnosticRuntimeService $service, string $organizationId, array $user): array =>
                $service->start($organizationId, $this->input(), (string) $user['id']),
            true,
        );
    }

    public function resumeAction(?string $session = null): ResponseInterface
    {
        $sessionId = $this->sessionId($session);
        return $this->call(
            fn (DiagnosticRuntimeService $service, string $organizationId): array =>
                $service->resume($organizationId, $sessionId),
        );
    }

    public function nextAction(?string $session = null): ResponseInterface
    {
        $sessionId = $this->sessionId($session);
        return $this->call(
            fn (DiagnosticRuntimeService $service, string $organizationId): array => [
                'next_question' => $service->nextQuestion($organizationId, $sessionId),
            ],
        );
    }

    public function answerAction(?string $session = null): ResponseInterface
    {
        $sessionId = $this->sessionId($session);
        return $this->call(function (DiagnosticRuntimeService $service, string $organizationId, array $user) use ($sessionId): array {
            $input = $this->input();
            return $service->answer(
                $organizationId,
                $sessionId,
                (string) ($input['answer'] ?? ''),
                (string) $user['id'],
            );
        }, true);
    }

    public function completeAction(?string $session = null): ResponseInterface
    {
        $sessionId = $this->sessionId($session);
        return $this->call(
            fn (DiagnosticRuntimeService $service, string $organizationId, array $user): array =>
                $service->complete($organizationId, $sessionId, (string) $user['id']),
            true,
        );
    }

    public function reportAction(?string $session = null): ResponseInterface
    {
        $sessionId = $this->sessionId($session);
        return $this->call(
            fn (DiagnosticRuntimeService $service, string $organizationId): array =>
                $service->report($organizationId, $sessionId),
        );
    }

    public function acceptAction(?string $session = null, ?string $recommendation = null): ResponseInterface
    {
        $sessionId = $this->sessionId($session);
        $recommendationId = (string) ($recommendation ?: $this->dispatcher->getParam('recommendation'));
        return $this->call(
            fn (DiagnosticRuntimeService $service, string $organizationId): array =>
                $service->accept($organizationId, $sessionId, $recommendationId),
            true,
        );
    }

    public function reDiagnosticAction(?string $session = null): ResponseInterface
    {
        $sessionId = $this->sessionId($session);
        return $this->call(
            fn (DiagnosticRuntimeService $service, string $organizationId, array $user): array =>
                $service->startReDiagnostic($organizationId, $sessionId, (string) $user['id']),
            true,
        );
    }

    public function compareAction(?string $before = null, ?string $after = null): ResponseInterface
    {
        $beforeId = (string) ($before ?: $this->dispatcher->getParam('before'));
        $afterId = (string) ($after ?: $this->dispatcher->getParam('after'));
        return $this->call(
            fn (DiagnosticRuntimeService $service, string $organizationId): array =>
                $service->comparison($organizationId, $beforeId, $afterId),
        );
    }

    private function call(callable $callback, bool $mutation = false): ResponseInterface
    {
        $user = $this->auth()->currentUser();
        if ($user === null) {
            return $this->jsonOut(401, ['ok' => false, 'error' => 'Authentication required.']);
        }
        if ($mutation && !$this->validMutation()) {
            return $this->jsonOut(400, ['ok' => false, 'error' => 'Invalid CSRF token.']);
        }

        try {
            /** @var DiagnosticRuntimeService $service */
            $service = $this->di->getShared('diagnosticRuntimeService');
            $data = $callback($service, $this->organization()->id(), $user);
            return $this->jsonOut(200, ['ok' => true, 'data' => $data]);
        } catch (Throwable $exception) {
            return $this->jsonOut(422, ['ok' => false, 'error' => $exception->getMessage()]);
        }
    }

    private function sessionId(?string $session): string
    {
        return (string) ($session ?: $this->dispatcher->getParam('session'));
    }

    private function input(): array
    {
        $value = $this->request->getJsonRawBody(true);
        return is_array($value) ? $value : (array) $this->request->getPost();
    }

    private function jsonOut(int $status, array $payload): ResponseInterface
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        $this->response->setJsonContent($payload);
        return $this->response;
    }
}
