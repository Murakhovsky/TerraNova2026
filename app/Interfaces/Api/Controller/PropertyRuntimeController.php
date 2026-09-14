<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Kernel\Module\ModuleCapabilityRegistry;
use Kernel\Module\ModuleReadinessDiagnostic;
use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;
use Throwable;

final class PropertyRuntimeController extends Controller
{
    public function indexAction(): Response
    {
        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');

        try {
            $organizationId = (string) $this->di->getShared('organizationContext')->id();
            $module = $this->propertyReadiness($organizationId);

            /** @var ModuleCapabilityRegistry $capabilities */
            $capabilities = $this->di->getShared('cosModuleCapabilityRegistry');

            return $this->response->setJsonContent([
                'module' => 'property',
                'organization_id' => $organizationId,
                'runtime' => $module,
                'capabilities' => $capabilities->capabilitiesFor('property'),
            ]);
        } catch (Throwable) {
            $this->response->setStatusCode(503);
            return $this->response->setJsonContent([
                'module' => 'property',
                'status' => 'UNAVAILABLE',
            ]);
        }
    }

    public function healthAction(): Response
    {
        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');

        try {
            $organizationId = (string) $this->di->getShared('organizationContext')->id();
            $module = $this->propertyReadiness($organizationId);
            $status = (string) ($module['status'] ?? 'UNKNOWN');
            $this->response->setStatusCode($status === 'READY' ? 200 : 503);

            return $this->response->setJsonContent([
                'module' => 'property',
                'organization_id' => $organizationId,
                'status' => $status,
                'runtime' => $module,
            ]);
        } catch (Throwable) {
            $this->response->setStatusCode(503);
            return $this->response->setJsonContent([
                'module' => 'property',
                'status' => 'UNAVAILABLE',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function propertyReadiness(string $organizationId): array
    {
        /** @var ModuleReadinessDiagnostic $diagnostic */
        $diagnostic = $this->di->getShared('cosModuleReadinessDiagnostic');
        $readiness = $diagnostic->diagnose($organizationId);

        foreach ((array) ($readiness['modules'] ?? []) as $module) {
            if (is_array($module) && ($module['id'] ?? null) === 'property') {
                return $module;
            }
        }

        return ['id' => 'property', 'status' => 'UNREGISTERED'];
    }
}
