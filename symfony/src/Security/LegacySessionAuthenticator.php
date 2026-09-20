<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class LegacySessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly LegacySessionReader $sessions)
    {
    }

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();

        if ($path === '/api/v1/health'
            || preg_match('#^/api/v1/integrations/crm/[1-9][0-9]*/webhook$#', $path) === 1) {
            return false;
        }

        if (str_starts_with($path, '/api/spatial/')) {
            if ($path === '/api/spatial/auth/token'
                || preg_match('/^Bearer\\s+\\S+$/i', trim((string) $request->headers->get('Authorization', ''))) === 1) {
                return false;
            }

            $publicScene = $request->isMethod('GET')
                && preg_match('#^/api/spatial/scenes/[A-Za-z0-9-]+$#', $path) === 1;
            $publicEvent = $request->isMethod('POST') && $path === '/api/spatial/events';
            if ($publicScene || $publicEvent) {
                return false;
            }

            return true;
        }

        return str_starts_with($path, '/api/v1')
            || str_starts_with($path, '/sales')
            || str_starts_with($path, '/cos/architecture')
            || str_starts_with($path, '/diagnostics/');
    }

    public function authenticate(Request $request): Passport
    {
        $sessionId = (string) $request->cookies->get($this->sessions->cookieName(), '');
        $identity = $this->sessions->read($sessionId);

        if ($identity === null) {
            throw new CustomUserMessageAuthenticationException('Manager authorization required.');
        }

        $identifier = (string) $identity['user_id'] . '|'
            . rawurlencode((string) ($identity['organization_id'] ?? ''));

        return new SelfValidatingPassport(new UserBadge($identifier));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if (self::isWebPath($request->getPathInfo())) {
            return new \Symfony\Component\HttpFoundation\RedirectResponse('/auth/login');
        }

        return str_starts_with($request->getPathInfo(), '/api/spatial/')
            ? self::spatialUnauthorized()
            : self::forbidden();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (self::isWebPath($request->getPathInfo())) {
            return new \Symfony\Component\HttpFoundation\RedirectResponse('/auth/login');
        }

        return str_starts_with($request->getPathInfo(), '/api/spatial/')
            ? self::spatialUnauthorized()
            : self::forbidden();
    }

    private static function isWebPath(string $path): bool
    {
        return str_starts_with($path, '/sales')
            || str_starts_with($path, '/cos/architecture')
            || str_starts_with($path, '/diagnostics/');
    }

    private static function spatialUnauthorized(): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'message' => 'Unauthorized.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    private static function forbidden(): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Manager authorization required.',
        ], Response::HTTP_FORBIDDEN);
    }
}
