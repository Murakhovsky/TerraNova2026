<?php
declare(strict_types=1);

namespace App\Web\PublicEdge;

use App\Security\LegacySessionReader;
use Domains\Content\Application\Contract\InboundContentWebhookInterface;
use Domains\Property\Application\Contract\PropertyFunnelAnalyticsInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class PublicEdgeController
{
    public function __construct(
        private PropertyFunnelAnalyticsInterface $analytics,
        private InboundContentWebhookInterface $contentWebhook,
        private LegacySessionReader $sessions,
    ) {
    }

    public function analytics(Request $request): JsonResponse
    {
        if (!$this->sameOrigin($request)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_origin'], Response::HTTP_BAD_REQUEST);
        }

        $ok = $this->analytics->recordPublicEvent(
            $request->request->all(),
            $this->sessionUser($request),
        );

        return new JsonResponse(
            ['ok' => $ok],
            $ok ? Response::HTTP_ACCEPTED : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public function contentWebhook(Request $request): JsonResponse
    {
        $result = $this->contentWebhook->handle(
            $request->getContent(),
            (string) $request->headers->get('X-TN-Signature', ''),
            (string) $request->headers->get('X-TN-Timestamp', ''),
            (string) $request->headers->get('X-TN-Idempotency-Key', ''),
        );

        return new JsonResponse((array) $result['payload'], (int) $result['status']);
    }

    private function sameOrigin(Request $request): bool
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin === '') {
            return true;
        }

        $originHost = (string) parse_url($origin, PHP_URL_HOST);
        return $originHost !== '' && strcasecmp($originHost, $request->getHost()) === 0;
    }

    /** @return array{id:int}|null */
    private function sessionUser(Request $request): ?array
    {
        $sessionId = trim((string) $request->cookies->get($this->sessions->cookieName(), ''));
        if ($sessionId === '') {
            return null;
        }

        $session = $this->sessions->read($sessionId);
        return $session !== null ? ['id' => (int) $session['user_id']] : null;
    }
}
