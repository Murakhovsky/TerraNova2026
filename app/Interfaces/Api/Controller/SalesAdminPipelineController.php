<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DomainException;
use Domains\Sales\Application\Contract\SalesPipelineAdministrationInterface;
use Domains\Sales\Application\Contract\SalesPipelineGovernanceInterface;
use Interfaces\Web\Controller\WebController;
use PDOException;
use Phalcon\Http\Response;
use Throwable;

final class SalesAdminPipelineController extends WebController
{
    public function pipelinesAction(): Response
    {
        return $this->read(fn (SalesPipelineAdministrationInterface $service, string $organizationId): array => $service->pipelines($organizationId));
    }

    public function pipelineAction(?string $id = null): Response
    {
        return $this->read(function (SalesPipelineAdministrationInterface $service, string $organizationId) use ($id): array {
            $pipeline = $service->pipeline($organizationId, $this->id($id));
            return $pipeline ?? ['_status' => 404, 'error' => 'Pipeline not found.'];
        });
    }

    public function createAction(): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->createPipeline($organizationId, $input, (string) $user['id']), 201);
    }

    public function updateAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->updatePipeline($organizationId, $this->id($id), $input, (string) $user['id']));
    }

    public function createStageAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->createStage($organizationId, $this->id($id), $input, (string) $user['id']), 201);
    }

    public function updateStageAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->updateStage($organizationId, $this->id($id), $input, (string) $user['id']));
    }

    public function reorderAction(?string $id = null): Response
    {
        return $this->write(function ($service, $organizationId, $user, $input) use ($id): array {
            $service->reorderStages($organizationId, $this->id($id), (array) ($input['stages'] ?? []), (int) ($input['version'] ?? 0), (string) $user['id']);
            return ['saved' => true];
        });
    }

    public function transitionsAction(?string $id = null): Response
    {
        return $this->write(function ($service, $organizationId, $user, $input) use ($id): array {
            $service->replaceTransitions($organizationId, $this->id($id), (array) ($input['transitions'] ?? []), (int) ($input['version'] ?? 0), (string) $user['id']);
            return ['saved' => true];
        });
    }

    public function lostReasonsAction(?string $id = null): Response
    {
        return $this->read(fn ($service, $organizationId) => $service->lostReasons($organizationId, $this->id($id)));
    }

    public function createLostReasonAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->createLostReason($organizationId, $this->id($id), $input, (string) $user['id']), 201);
    }

    public function updateLostReasonAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->updateLostReason($organizationId, $this->id($id), $input, (string) $user['id']));
    }

    public function validateAction(?string $id = null): Response
    {
        return $this->read(fn ($service, $organizationId) => $service->validatePipeline($organizationId, $this->id($id)));
    }

    public function cloneAction(?string $id = null): Response
    {
        $user = $this->adminUser();
        if ($user instanceof Response) return $user;
        if (!$this->validMutation()) return $this->json(400, ['ok' => false, 'error' => 'Invalid CSRF token.']);
        try {
            return $this->json(201, ['ok' => true, 'data' => $this->governance()->cloneToDraft(
                $this->organization()->id(),
                $this->id($id),
                $this->input(),
                (string) $user['id'],
            )]);
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function revisionsAction(?string $id = null): Response
    {
        $user = $this->adminUser();
        if ($user instanceof Response) return $user;
        try {
            return $this->json(200, ['ok' => true, 'data' => $this->governance()->revisions(
                $this->organization()->id(),
                $this->id($id),
                (int) $this->request->getQuery('limit', 'int', 100),
            )]);
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function service(): SalesPipelineAdministrationInterface
    {
        return $this->di->getShared('salesPipelineAdministration');
    }

    private function governance(): SalesPipelineGovernanceInterface
    {
        return $this->di->getShared('salesPipelineGovernance');
    }

    private function read(callable $reader): Response
    {
        $user = $this->adminUser();
        if ($user instanceof Response) return $user;
        try {
            $data = $reader($this->service(), $this->organization()->id());
            $status = is_array($data) ? (int) ($data['_status'] ?? 200) : 200;
            if (is_array($data)) unset($data['_status']);
            return $this->json($status, $status >= 400 ? ['ok' => false] + $data : ['ok' => true, 'data' => $data]);
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function write(callable $writer, int $successStatus = 200): Response
    {
        $user = $this->adminUser();
        if ($user instanceof Response) return $user;
        if (!$this->validMutation()) return $this->json(400, ['ok' => false, 'error' => 'Invalid CSRF token.']);
        try {
            return $this->json($successStatus, ['ok' => true, 'data' => $writer($this->service(), $this->organization()->id(), $user, $this->input())]);
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function adminUser(): array|Response
    {
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isAdmin($user)) {
            return $this->json(403, ['ok' => false, 'error' => 'Sales Administrator authorization required.']);
        }
        return $user;
    }

    private function input(): array
    {
        $json = $this->request->getJsonRawBody(true);
        return is_array($json) ? $json : (array) $this->request->getPost();
    }

    private function id(?string $id): string
    {
        $value = trim((string) ($id ?: $this->dispatcher->getParam('id')));
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value)) throw new DomainException('Invalid configuration id.');
        return $value;
    }

    private function error(Throwable $exception): Response
    {
        $message = $exception->getMessage();
        $status = match (true) {
            $message === 'CONFIGURATION_CONFLICT' => 409,
            $exception instanceof DomainException => 422,
            $exception instanceof PDOException && (string) $exception->getCode() === '23000' => 409,
            default => 500,
        };
        return $this->json($status, ['ok' => false, 'error' => $message]);
    }

    private function json(int $status, array $payload): Response
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }
}
