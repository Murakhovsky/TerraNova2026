<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Sales\Application\UseCase\ReceiveCrmWebhook;
use InvalidArgumentException;
use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;
use Throwable;

final class CrmWebhookController extends Controller
{
    public function receiveAction(?string $organization = null, ?string $provider = null): Response
    {
        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');
        if (!$this->request->isPost()) {
            return $this->respond(405, ['ok' => false, 'error' => 'POST required.']);
        }
        $organization = (string) ($organization ?: $this->dispatcher->getParam('organization'));
        $provider = (string) ($provider ?: $this->dispatcher->getParam('provider'));
        try {
            $rawPayload = (string) $this->request->getRawBody();
            $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new InvalidArgumentException('JSON object required.');
            /** @var ReceiveCrmWebhook $receiver */
            $receiver = $this->di->getShared('salesReceiveCrmWebhook');
            $id = $receiver->execute(
                $organization,
                $provider,
                trim((string) ($this->request->getHeader('X-CRM-Event-Id') ?: ($payload['event_id'] ?? ''))),
                trim((string) ($payload['event_type'] ?? '')),
                $payload,
                (string) $this->request->getHeader('X-CRM-Signature'),
                $rawPayload,
            );
            return $this->respond(202, ['ok' => true, 'inbox_id' => $id]);
        } catch (InvalidArgumentException $error) {
            return $this->respond(401, ['ok' => false, 'error' => $error->getMessage()]);
        } catch (Throwable) {
            return $this->respond(503, ['ok' => false, 'error' => 'CRM webhook could not be accepted.']);
        }
    }

    private function respond(int $status, array $payload): Response
    {
        $this->response->setStatusCode($status);
        return $this->response->setJsonContent($payload);
    }
}
