<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    private const CSP = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: https:; "
        . "font-src 'self' data:; "
        . "connect-src 'self' https: wss:; "
        . "media-src 'self' blob: https:; "
        . "frame-src 'self' https:; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "upgrade-insecure-requests";

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
            $headers->set('Content-Security-Policy', self::CSP);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -4096],
        ];
    }
}
