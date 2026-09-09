<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Interfaces\Web\Controller\WebController;
use Kernel\Module\ActiveModuleResolver;
use Phalcon\Http\Response;
use Throwable;

final class PlatformModuleController extends WebController
{
    public function indexAction(): Response
    {
        $this->view->disable();
        try {
            /** @var ActiveModuleResolver $modules */
            $modules = $this->di->getShared('cosActiveModuleResolver');
            $organizationId = $this->organization()->id();
            $payload = [
                'ok' => true,
                'data' => [
                    'organization_id' => $organizationId,
                    'modules' => $modules->describe($organizationId),
                ],
            ];
            $this->response->setStatusCode(200);
        } catch (Throwable $error) {
            $payload = ['ok' => false, 'error' => 'Platform module state is unavailable.'];
            $this->response->setStatusCode(500);
            $this->di->getShared('cosLogger')->error('platform.modules.read_failed', ['error' => $error->getMessage()]);
        }

        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }
}
