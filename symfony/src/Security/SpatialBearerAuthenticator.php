<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class SpatialBearerAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly SpatialJwtCodec $tokens,
        private readonly string $organizationId,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/api/spatial/')
            && preg_match('/^Bearer\s+\S+$/i', trim((string) $request->headers->get('Authorization', ''))) === 1;
    }

    public function authenticate(Request $request): Passport
    {
        $authorization = trim((string) $request->headers->get('Authorization', ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) !== 1) {
            throw new AuthenticationException('Invalid Spatial bearer token.');
        }

        $claims = $this->tokens->decode(trim($match[1]), $this->organizationId);
        if ($claims === null) {
            throw new AuthenticationException('Invalid Spatial bearer token.');
        }

        return new SelfValidatingPassport(new UserBadge(
            (string) $claims['sub'] . '|' . rawurlencode($claims['org'])
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['ok' => false, 'error' => 'Spatial authorization required.'], Response::HTTP_UNAUTHORIZED);
    }
}
