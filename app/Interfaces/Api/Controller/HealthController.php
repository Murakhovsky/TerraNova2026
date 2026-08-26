<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Kernel\Operations\Contract\OperationsReadModelInterface;
use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;
use Throwable;

final class HealthController extends Controller
{
    public function indexAction(): Response
    {
        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');
        try {
            /** @var OperationsReadModelInterface $query */
            $query = $this->di->getShared('cosOperationsReadModel');
            $health = $query->health();
            $this->response->setStatusCode($health['status'] === 'ok' ? 200 : 503);
            return $this->response->setJsonContent($health);
        } catch (Throwable) {
            $this->response->setStatusCode(503);
            return $this->response->setJsonContent(['status' => 'down', 'database' => false]);
        }
    }
}
