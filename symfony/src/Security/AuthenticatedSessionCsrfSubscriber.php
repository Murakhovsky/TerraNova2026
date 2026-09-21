<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class AuthenticatedSessionCsrfSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SessionCsrfValidator $csrf,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 6],
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

        if (!$request->hasSession() || (int) $request->getSession()->get('tn_auth_user_id', 0) <= 0) {
            return;
        }

        if (preg_match('/^Bearer\s+\S+$/i', trim((string) $request->headers->get('Authorization', ''))) === 1) {
            return;
        }

        if (!$this->protectedSessionPath($request->getPathInfo()) || $this->csrf->isValid($request)) {
            return;
        }

        if (str_starts_with($request->getPathInfo(), '/api/')) {
            $event->setResponse(new JsonResponse([
                'ok' => false,
                'error' => 'invalid_csrf_token',
                'message' => 'Invalid CSRF token.',
            ], Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store, private']));

            return;
        }

        $event->setResponse(new Response(
            'Invalid CSRF token.',
            Response::HTTP_FORBIDDEN,
            ['Cache-Control' => 'no-store, private'],
        ));
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
