<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class RequestRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SecurityRateLimiter $limiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 7],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $policy = $this->policy($request);
        if ($policy === null) {
            return;
        }

        [$bucket, $limit, $window] = $policy;
        $decision = $this->limiter->consume(
            $bucket,
            $this->subject($request),
            $limit,
            $window,
        );

        if ($decision->allowed) {
            return;
        }

        $headers = [
            'Retry-After' => (string) $decision->retryAfterSeconds,
            'Cache-Control' => 'no-store, private',
            'X-RateLimit-Limit' => (string) $decision->limit,
            'X-RateLimit-Remaining' => '0',
        ];

        if (str_starts_with($request->getPathInfo(), '/api/')) {
            $event->setResponse(new JsonResponse([
                'ok' => false,
                'error' => 'rate_limit_exceeded',
                'message' => 'Too many requests. Retry later.',
            ], Response::HTTP_TOO_MANY_REQUESTS, $headers));

            return;
        }

        $event->setResponse(new Response(
            'Too many requests. Retry later.',
            Response::HTTP_TOO_MANY_REQUESTS,
            $headers,
        ));
    }

    /** @return array{string,int,int}|null */
    private function policy(Request $request): ?array
    {
        $path = $request->getPathInfo();

        if ($path === '/auth/login') {
            return ['auth.login', 10, 300];
        }

        if ($path === '/auth/register') {
            return ['auth.register', 5, 900];
        }

        if ($path === '/analytics/track') {
            return ['public.analytics', 120, 60];
        }

        if ($path === '/api/spatial/events') {
            return ['spatial.events', 120, 60];
        }

        if ($this->authenticatedSession($request) && $this->protectedSessionPath($path)) {
            return ['authenticated.write', 240, 60];
        }

        return null;
    }

    private function subject(Request $request): string
    {
        if ($this->authenticatedSession($request)) {
            return 'user:' . (string) $request->getSession()->get('tn_auth_user_id');
        }

        return 'ip:' . ($request->getClientIp() ?: 'unknown');
    }

    private function authenticatedSession(Request $request): bool
    {
        return $request->hasSession()
            && (int) $request->getSession()->get('tn_auth_user_id', 0) > 0;
    }

    private function protectedSessionPath(string $path): bool
    {
        if (preg_match('#^/api/v1/integrations/crm/[1-9][0-9]*/webhook$#', $path) === 1) {
            return false;
        }

        return str_starts_with($path, '/api/v1')
            || str_starts_with($path, '/api/spatial')
            || str_starts_with($path, '/cabinet')
            || str_starts_with($path, '/workspace')
            || str_starts_with($path, '/sales')
            || str_starts_with($path, '/client-case')
            || str_starts_with($path, '/cos/')
            || str_starts_with($path, '/admin')
            || preg_match('#^/property/(?:manage|listing|submissions|submission|presentationShare)(?:/|$)#', $path) === 1
            || str_starts_with($path, '/diagnostics')
            || str_starts_with($path, '/spatial')
            || str_starts_with($path, '/dev');
    }
}
