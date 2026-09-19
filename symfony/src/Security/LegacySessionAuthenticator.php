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

        return str_starts_with($path, '/api/v1');
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
        return self::forbidden();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::forbidden();
    }

    private static function forbidden(): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Manager authorization required.',
        ], Response::HTTP_FORBIDDEN);
    }
}
