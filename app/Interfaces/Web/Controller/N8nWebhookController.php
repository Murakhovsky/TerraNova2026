<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Content\Application\Contract\InboundContentWebhookInterface;

class N8nWebhookController extends ControllerBase
{
    public function contentAction(): \Phalcon\Http\ResponseInterface
    {
        if (!$this->request->isPost()) {
            return $this->json(['ok' => false, 'message' => 'Method not allowed.'], 405);
        }

        $service = $this->di->getShared('frontendN8nWebhookService');
        assert($service instanceof InboundContentWebhookInterface);
        $result = $service->handle(
            (string) $this->request->getRawBody(),
            (string) $this->request->getHeader('X-TN-Signature'),
            (string) $this->request->getHeader('X-TN-Timestamp'),
            (string) $this->request->getHeader('X-TN-Idempotency-Key')
        );

        return $this->json((array) $result['payload'], (int) $result['status']);
    }
}

