<?php

declare(strict_types=1);

namespace App\Web\Experience\Locale;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class LocaleController
{
    private const SUPPORTED_LOCALES = ['uk', 'en'];

    public function __construct(private CsrfTokenManagerInterface $csrfTokens)
    {
    }

    public function change(Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->csrfTokens->isTokenValid(new CsrfToken('cos_locale_switch', $token))) {
            throw new AccessDeniedHttpException('Invalid locale switch token.');
        }

        $locale = strtolower(trim((string) $request->request->get('locale', '')));
        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new BadRequestHttpException('Unsupported locale.');
        }

        $request->getSession()->set('cos_locale', $locale);

        return new RedirectResponse($this->safeReturnPath($request), Response::HTTP_SEE_OTHER);
    }

    private function safeReturnPath(Request $request): string
    {
        $referer = trim((string) $request->headers->get('referer', ''));
        if ($referer === '') {
            return '/admin';
        }

        $parts = parse_url($referer);
        if (!is_array($parts)) {
            return '/admin';
        }

        $host = $parts['host'] ?? null;
        if (is_string($host) && strcasecmp($host, $request->getHost()) !== 0) {
            return '/admin';
        }

        $path = (string) ($parts['path'] ?? '/');
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || $path === '/workspace/locale') {
            return '/admin';
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $path . $query;
    }
}
