<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

class AnalyticsController extends ControllerBase
{
    public function trackAction(): \Phalcon\Http\ResponseInterface
    {
        if (!$this->request->isPost() || !$this->isSameOrigin()) {
            return $this->json(['ok' => false], 400);
        }

        $ok = $this->analyticsService()->recordPublicEvent(
            (array) $this->request->getPost(),
            $this->currentUser()
        );

        return $this->json(['ok' => $ok], $ok ? 202 : 422);
    }

    private function isSameOrigin(): bool
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return true;
        }

        $originHost = (string) parse_url($origin, PHP_URL_HOST);
        $requestHost = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];

        return $originHost !== '' && strcasecmp($originHost, $requestHost) === 0;
    }
}

