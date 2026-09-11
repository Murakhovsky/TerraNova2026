<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DomainException;
use Domains\Sales\Application\Contract\SalesAgentAdministrationInterface;
use Interfaces\Web\Controller\WebController;
use PDOException;
use Phalcon\Http\Response;
use Throwable;

final class SalesAdminAgentController extends WebController
{
    public function catalogAction(): Response
    {
        return $this->read(fn (SalesAgentAdministrationInterface $service, string $organizationId): array => $service->catalog());
    }

    public function agentsAction(): Response
    {
        return $this->read(fn (SalesAgentAdministrationInterface $service, string $organizationId): array => $service->agents($organizationId));
    }

    public function agentAction(?string $name = null): Response
    {
        return $this->read(function (SalesAgentAdministrationInterface $service, string $organizationId) use ($name): array {
            $agent = $service->agent($organizationId, $this->name($name));
            return $agent ?? ['_status' => 404, 'error' => 'Sales agent not found.'];
        });
    }

    public function updateAction(?string $name = null): Response
    {
        return $this->write(function (SalesAgentAdministrationInterface $service, string $organizationId, array $user, array $input) use ($name): array {
            return $service->update(
                $organizationId,
                $this->name($name),
                $input,
                (int) ($input['configuration_version'] ?? $input['version'] ?? 0),
                (string) $user['id'],
            );
        });
    }

    public function testAction(?string $name = null): Response
    {
        return $this->write(fn (SalesAgentAdministrationInterface $service, string $organizationId, array $user, array $input): array =>
            $service->test($organizationId, $this->name($name), $input, (string) $user['id'])
        );
    }

    public function revisionsAction(?string $name = null): Response
    {
        return $this->read(fn (SalesAgentAdministrationInterface $service, string $organizationId): array =>
            $service->revisions($organizationId, $this->name($name), (int) $this->request->getQuery('limit', 'int', 100))
        );
    }

    private function service(): SalesAgentAdministrationInterface
    {
        return $this->di->getShared('salesAgentAdministration');
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

    private function write(callable $writer): Response
    {
        $user = $this->adminUser();
        if ($user instanceof Response) return $user;
        if (!$this->validMutation()) return $this->json(400, ['ok' => false, 'error' => 'Invalid CSRF token.']);
        try {
            return $this->json(200, ['ok' => true, 'data' => $writer($this->service(), $this->organization()->id(), $user, $this->input())]);
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

    private function name(?string $name): string
    {
        $value = trim((string) ($name ?: $this->dispatcher->getParam('name')));
        if (!preg_match('/^[A-Za-z0-9_.-]{3,160}$/', $value)) throw new DomainException('Invalid agent name.');
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
