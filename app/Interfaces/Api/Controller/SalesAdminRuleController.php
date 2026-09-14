<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DomainException;
use Domains\Sales\Application\Contract\SalesRuleAdministrationInterface;
use Interfaces\Web\Controller\WebController;
use PDOException;
use Phalcon\Http\Response;
use Throwable;

final class SalesAdminRuleController extends WebController
{
    public function catalogAction(): Response
    {
        return $this->read(fn (SalesRuleAdministrationInterface $service, string $organizationId): array => $service->catalog());
    }

    public function rulesAction(): Response
    {
        return $this->read(fn (SalesRuleAdministrationInterface $service, string $organizationId): array => $service->rules($organizationId));
    }

    public function ruleAction(?string $id = null): Response
    {
        return $this->read(function (SalesRuleAdministrationInterface $service, string $organizationId) use ($id): array {
            $rule = $service->rule($organizationId, $this->id($id));
            return $rule ?? ['_status' => 404, 'error' => 'Sales rule not found.'];
        });
    }

    public function createAction(): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->createDraft($organizationId, $input, (string) $user['id']), 201);
    }

    public function updateAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->updateDraft($organizationId, $this->id($id), $input, (string) $user['id']));
    }

    public function activateAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->activate($organizationId, $this->id($id), (int) ($input['configuration_version'] ?? $input['version'] ?? 0), (string) $user['id']));
    }

    public function disableAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->disable($organizationId, $this->id($id), (int) ($input['configuration_version'] ?? $input['version'] ?? 0), (string) $user['id']));
    }

    public function archiveAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->archive($organizationId, $this->id($id), (int) ($input['configuration_version'] ?? $input['version'] ?? 0), (string) $user['id']));
    }

    public function restoreSystemAction(?string $id = null): Response
    {
        return $this->write(fn ($service, $organizationId, $user, $input) => $service->restoreSystem($organizationId, $this->id($id), (int) ($input['configuration_version'] ?? $input['version'] ?? 0), (string) $user['id']));
    }

    public function dryRunAction(?string $id = null): Response
    {
        return $this->read(fn ($service, $organizationId) => $service->dryRun($organizationId, $this->id($id)));
    }

    public function revisionsAction(?string $id = null): Response
    {
        return $this->read(fn ($service, $organizationId) => $service->revisions($organizationId, $this->id($id), (int) $this->request->getQuery('limit', 'int', 100)));
    }

    private function service(): SalesRuleAdministrationInterface
    {
        return $this->di->getShared('salesRuleAdministration');
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
        if ($user === null || !$this->auth()->isAdmin($user)) return $this->json(403, ['ok' => false, 'error' => 'Sales Administrator authorization required.']);
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
        if (!preg_match('/^[A-Za-z0-9_.-]{8,64}$/', $value)) throw new DomainException('Invalid rule id.');
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
