<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\MethodologyWorkbenchService;
use Interfaces\Web\Controller\WebController;
use Phalcon\Http\ResponseInterface;
use Throwable;

final class DiagnosticMethodologyWorkbenchController extends WebController
{
    public function scenariosAction(?string $id = null, ?string $version = null): ResponseInterface
    {
        [$packId, $methodologyVersion] = $this->identity($id, $version);
        return $this->call(fn (MethodologyWorkbenchService $service, string $org): array => [
            'scenarios' => $service->scenarios($org, $packId, $methodologyVersion),
        ]);
    }

    public function deleteScenarioAction(
        ?string $id = null,
        ?string $version = null,
        ?string $scenario = null,
    ): ResponseInterface {
        [$packId, $methodologyVersion] = $this->identity($id, $version);
        $scenarioId = (string) ($scenario ?: $this->dispatcher->getParam('scenario'));
        return $this->call(function (MethodologyWorkbenchService $service, string $org, array $user) use ($packId, $methodologyVersion, $scenarioId): array {
            $service->deleteScenario($org, $packId, $methodologyVersion, $scenarioId, (string) $user['id']);
            return ['deleted' => true];
        }, 'admin');
    }

    public function cloneScenarioAction(
        ?string $id = null,
        ?string $version = null,
        ?string $scenario = null,
    ): ResponseInterface {
        [$packId, $methodologyVersion] = $this->identity($id, $version);
        $scenarioId = (string) ($scenario ?: $this->dispatcher->getParam('scenario'));
        return $this->call(function (MethodologyWorkbenchService $service, string $org, array $user) use ($packId, $methodologyVersion, $scenarioId): array {
            $input = $this->input();
            return [
                'scenario' => $service->cloneScenario(
                    $org,
                    $packId,
                    $methodologyVersion,
                    $scenarioId,
                    (string) ($input['id'] ?? ''),
                    (string) ($input['name'] ?? ''),
                    (string) $user['id'],
                ),
            ];
        }, 'admin');
    }

    public function runScenarioAction(
        ?string $id = null,
        ?string $version = null,
        ?string $scenario = null,
    ): ResponseInterface {
        [$packId, $methodologyVersion] = $this->identity($id, $version);
        $scenarioId = (string) ($scenario ?: $this->dispatcher->getParam('scenario'));
        return $this->call(fn (MethodologyWorkbenchService $service, string $org): array => [
            'result' => $service->runScenario($org, $packId, $methodologyVersion, $scenarioId),
        ], 'admin');
    }

    public function runAction(?string $session = null): ResponseInterface
    {
        $sessionId = (string) ($session ?: $this->dispatcher->getParam('session'));
        return $this->call(fn (MethodologyWorkbenchService $service, string $org): array => [
            'run' => $service->diagnosticRun($org, $sessionId),
        ], 'admin');
    }

    public function permissionMatrixAction(): ResponseInterface
    {
        return $this->call(fn (MethodologyWorkbenchService $service, string $org): array => [
            'matrix' => $service->permissionMatrix($org),
        ], 'publisher');
    }

    public function permissionOverrideAction(): ResponseInterface
    {
        return $this->call(fn (MethodologyWorkbenchService $service, string $org, array $user): array => [
            'matrix' => $service->setPermission($org, (int) $user['id'], $this->input()),
        ], 'publisher');
    }

    private function call(callable $callback, string $access = 'viewer'): ResponseInterface
    {
        $user = $this->auth()->currentUser();
        if ($user === null) {
            return $this->jsonOut(401, ['ok' => false, 'error' => 'Authentication required.']);
        }

        $organizationId = $this->organization()->id();
        /** @var DiagnosticMethodologyAccess $authorization */
        $authorization = $this->di->getShared('diagnosticMethodologyAccess');
        $permission = match ($access) {
            'admin' => DiagnosticMethodologyAccess::EDIT,
            'publisher' => DiagnosticMethodologyAccess::PUBLISH,
            default => DiagnosticMethodologyAccess::VIEW,
        };
        if (!$authorization->allows($organizationId, (int) $user['id'], $permission)) {
            return $this->jsonOut(403, ['ok' => false, 'error' => 'Missing permission: ' . $permission]);
        }
        if ($this->request->isPost() && $access !== 'viewer' && !$this->validMutation()) {
            return $this->jsonOut(400, ['ok' => false, 'error' => 'Invalid CSRF token.']);
        }

        try {
            return $this->jsonOut(200, [
                'ok' => true,
                'data' => $callback($this->workbench(), $organizationId, $user),
            ]);
        } catch (Throwable $exception) {
            return $this->jsonOut(422, ['ok' => false, 'error' => $exception->getMessage()]);
        }
    }

    private function workbench(): MethodologyWorkbenchService
    {
        /** @var MethodologyWorkbenchService $service */
        $service = $this->di->getShared('diagnosticMethodologyWorkbench');
        return $service;
    }

    /** @return array{string,string} */
    private function identity(?string $id, ?string $version): array
    {
        return [
            (string) ($id ?: $this->dispatcher->getParam('id')),
            (string) ($version ?: $this->dispatcher->getParam('version')),
        ];
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        $value = $this->request->getJsonRawBody(true);
        return is_array($value) ? $value : (array) $this->request->getPost();
    }

    private function jsonOut(int $status, array $data): ResponseInterface
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        $this->response->setJsonContent($data);
        return $this->response;
    }
}
