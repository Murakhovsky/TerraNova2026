<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Interfaces\Web\Controller\WebController;
use InvalidArgumentException;
use Kernel\Module\EffectiveModuleContext;
use Kernel\Module\ModuleControlService;
use Phalcon\Http\Response;
use RuntimeException;
use Throwable;

final class PlatformModuleController extends WebController
{
    public function indexAction(): Response
    {
        $this->view->disable();
        $user = $this->auth()->currentUser();
        if ($user === null) {
            return $this->json(401, ['ok' => false, 'error' => 'Authentication required.']);
        }

        try {
            /** @var EffectiveModuleContext $context */
            $context = $this->di->getShared('cosEffectiveModuleContext');

            return $this->json(200, [
                'ok' => true,
                'data' => $context->describe($this->organization()->id()),
            ]);
        } catch (Throwable $error) {
            $this->di->getShared('cosLogger')->error('platform.modules.read_failed', ['error' => $error->getMessage()]);
            return $this->json(500, ['ok' => false, 'error' => 'Platform module state is unavailable.']);
        }
    }

    public function installAction(?string $id = null): Response { return $this->mutate($id, 'install'); }
    public function upgradeAction(?string $id = null): Response { return $this->mutate($id, 'upgrade'); }
    public function enableAction(?string $id = null): Response { return $this->mutate($id, 'enable'); }
    public function disableAction(?string $id = null): Response { return $this->mutate($id, 'disable'); }
    public function uninstallAction(?string $id = null): Response { return $this->mutate($id, 'uninstall'); }

    private function mutate(?string $id, string $operation): Response
    {
        $this->view->disable();
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isAdmin($user)) {
            return $this->json(403, ['ok' => false, 'error' => 'Administrator authorization required.']);
        }

        $moduleId = (string) ($id ?: $this->dispatcher->getParam('id'));
        if (!$this->validMutation() || !preg_match('/^[a-z][a-z0-9_]*$/', $moduleId)) {
            return $this->json(400, ['ok' => false, 'error' => 'Invalid request, module id or CSRF token.']);
        }

        $correlationId = trim((string) $this->request->getHeader('X-Correlation-ID'));
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $correlationId)) {
            $correlationId = bin2hex(random_bytes(16));
        }
        $reason = trim((string) $this->request->getPost('reason', 'string', '')) ?: null;

        try {
            /** @var ModuleControlService $control */
            $control = $this->di->getShared('cosModuleControlService');
            $arguments = [$this->organization()->id(), $moduleId, (string) $user['id'], $correlationId, $reason];
            $state = match ($operation) {
                'install' => $control->install(...$arguments),
                'upgrade' => $control->upgrade(...$arguments),
                'enable' => $control->enable(...$arguments),
                'disable' => $control->disable(...$arguments),
                'uninstall' => $control->uninstall(...$arguments),
                default => throw new InvalidArgumentException('Unsupported module operation.'),
            };

            return $this->json(200, [
                'ok' => true,
                'data' => ['operation' => $operation, 'correlation_id' => $correlationId, 'module' => $state],
            ]);
        } catch (RuntimeException|InvalidArgumentException $error) {
            return $this->json(409, ['ok' => false, 'error' => $error->getMessage()]);
        } catch (Throwable $error) {
            $this->di->getShared('cosLogger')->error('platform.modules.mutation_failed', [
                'module_id' => $moduleId,
                'operation' => $operation,
                'correlation_id' => $correlationId,
                'error' => $error->getMessage(),
            ]);
            return $this->json(500, ['ok' => false, 'error' => 'Module lifecycle operation failed.']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(int $status, array $payload): Response
    {
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }
}
