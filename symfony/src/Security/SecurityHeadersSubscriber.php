<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const CSP_NONCE_ATTRIBUTE = 'cos_csp_nonce';

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->nonce($event->getRequest());
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        $contentType = strtolower((string) $headers->get('Content-Type', ''));
        if (str_contains($contentType, 'text/html')) {
            $headers->set('Content-Security-Policy', $this->csp($this->nonce($event->getRequest())));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::RESPONSE => ['onResponse', -4096],
        ];
    }

    private function nonce(Request $request): string
    {
        $existing = trim((string) $request->attributes->get(self::CSP_NONCE_ATTRIBUTE, ''));
        if ($existing !== '') {
            return $existing;
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $request->attributes->set(self::CSP_NONCE_ATTRIBUTE, $nonce);

        return $nonce;
    }

    private function csp(string $nonce): string
    {
        return "default-src 'self'; "
            . "script-src 'self' 'nonce-{$nonce}' data:; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob: https:; "
            . "font-src 'self' data:; "
            . "connect-src 'self' https: wss:; "
            . "media-src 'self' blob: https:; "
            . "frame-src 'self' https:; "
            . "worker-src 'self'; "
            . "manifest-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'none'; "
            . "form-action 'self'; "
            . "upgrade-insecure-requests";
    }
}
