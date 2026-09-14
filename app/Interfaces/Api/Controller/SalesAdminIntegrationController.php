<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DomainException;
use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Domains\Sales\Model\SalesCapability;
use Interfaces\Web\Controller\WebController;
use PDOException;
use Phalcon\Http\Response;
use Throwable;

final class SalesAdminIntegrationController extends WebController
{
    public function catalogAction(): Response
    {
        return $this->read(fn ($service, $organizationId) => $service->catalog());
    }

    public function integrationsAction(): Response
    {
        return $this->read(fn ($service, $organizationId) => $service->integrations($organizationId));
    }

    public function routingOptionsAction(): Response
    {
        return $this->read(fn ($service, $organizationId) => $service->routingOptions($organizationId));
    }

    public function integrationAction(?string $id = null): Response
    {
        return $this->read(function ($service, $organizationId) use ($id) {
            $integration = $service->integration($organizationId, $this->integrationId($id));
            if ($integration === null) {
                throw new DomainException('Sales integration was not found.');
            }
            return $integration;
        });
    }

    public function createAction(): Response
    {
        return $this->write(
            fn ($service, $organizationId, $user, $input) => $service->create($organizationId, $input, (string) $user['id']),
            201,
        );
    }

    public function updateAction(?string $id = null): Response
    {
        return $this->write(
            fn ($service, $organizationId, $user, $input) => $service->update(
                $organizationId,
                $this->integrationId($id),
                $input,
                (int) ($input['configuration_version'] ?? 0),
                (string) $user['id'],
            ),
        );
    }

    public function testAction(?string $id = null): Response
    {
        return $this->write(
            fn ($service, $organizationId) => $service->testConnection($organizationId, $this->integrationId($id)),
        );
    }

    public function routeAction(?string $id = null): Response
    {
        return $this->write(
            fn ($service, $organizationId, $user, $input) => $service->saveRoute(
                $organizationId,
                $this->integrationId($id),
                $input,
                (string) $user['id'],
            ),
        );
    }

    public function revisionsAction(?string $id = null): Response
    {
        return $this->read(
            fn ($service, $organizationId) => $service->revisions(
                $organizationId,
                $this->integrationId($id),
                (int) $this->request->getQuery('limit', 'int', 100),
            ),
        );
    }

    private function service(): SalesIntegrationAdministrationInterface
    {
        return $this->di->getShared('salesIntegrationAdministration');
    }

    private function read(callable $callback): Response
    {
        $user = $this->admin();
        if ($user instanceof Response) {
            return $user;
        }
        try {
            return $this->json(200, [
                'ok' => true,
                'data' => $callback($this->service(), $this->organization()->id()),
            ]);
        } catch (Throwable $error) {
            return $this->error($error);
        }
    }

    private function write(callable $callback, int $status = 200): Response
    {
        $user = $this->admin();
        if ($user instanceof Response) {
            return $user;
        }
        if (!$this->validMutation()) {
            return $this->json(400, ['ok' => false, 'error' => 'Invalid CSRF token.']);
        }
        try {
            return $this->json($status, [
                'ok' => true,
                'data' => $callback($this->service(), $this->organization()->id(), $user, $this->input()),
            ]);
        } catch (Throwable $error) {
            return $this->error($error);
        }
    }

    private function admin(): array|Response
    {
        $user = $this->auth()->currentUser();
        if ($user !== null && (
            $this->auth()->isAdmin($user)
            || $this->di->getShared('salesAccessControl')->hasCapability(
                $this->organization()->id(),
                (int) $user['id'],
                SalesCapability::AdminIntegrationsManage->value,
            )
        )) {
            return $user;
        }
        return $this->json(403, ['ok' => false, 'error' => 'Sales Integrations administration capability required.']);
    }

    private function input(): array
    {
        $json = $this->request->getJsonRawBody(true);
        return is_array($json) ? $json : (array) $this->request->getPost();
    }

    private function integrationId(?string $id): int
    {
        $value = (string) ($id ?: $this->dispatcher->getParam('id'));
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new DomainException('Invalid integration id.');
        }
        return (int) $value;
    }

    private function error(Throwable $error): Response
    {
        $message = $error->getMessage();
        $status = match (true) {
            $message === 'CONFIGURATION_CONFLICT' => 409,
            $message === 'Sales integration was not found.',
            $message === 'Integration route was not found.' => 404,
            $error instanceof DomainException => 422,
            $error instanceof PDOException && (string) $error->getCode() === '23000' => 409,
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
