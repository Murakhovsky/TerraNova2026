<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Phalcon\Http\ResponseInterface;

final class DeprecatedModuleController extends WebController
{
    public function goneAction(): ResponseInterface
    {
        $this->view->disable();
        $this->response->setStatusCode(410, 'Gone');
        $this->response->setContentType('application/json', 'UTF-8');
        $this->response->setJsonContent([
            'ok' => false,
            'error' => 'legacy_module_not_available',
            'message' => 'This legacy module is not available in the main web application.',
        ]);

        return $this->response;
    }
}
