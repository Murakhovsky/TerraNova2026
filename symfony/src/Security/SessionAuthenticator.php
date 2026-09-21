<?php
declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

final class SessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function supports(Request $request): ?bool
    {
        $path=$request->getPathInfo();
        if ($path==='/api/v1/health' || preg_match('#^/api/v1/integrations/crm/[1-9][0-9]*/webhook$#',$path)===1) return false;
        if (in_array($request->getMethod(),['GET','HEAD'],true) && preg_match('#^/api/v1/public/properties(?:/|$)#',$path)===1) return false;
        if (in_array($request->getMethod(),['GET','HEAD'],true) && preg_match('#^/spatial/scene/[A-Za-z0-9_-]+$#',$path)===1) return false;

        if (str_starts_with($path,'/api/spatial/')) {
            if ($path==='/api/spatial/auth/token' || preg_match('/^Bearer\s+\S+$/i',trim((string)$request->headers->get('Authorization','')))===1) return false;
            if ((in_array($request->getMethod(),['GET','HEAD'],true) && preg_match('#^/api/spatial/scenes/[A-Za-z0-9-]+$#',$path)===1)
                || ($request->isMethod('POST') && $path==='/api/spatial/events')) return false;
            return true;
        }

        return str_starts_with($path,'/api/v1') || self::isWebPath($path);
    }

    public function authenticate(Request $request): Passport
    {
        $session=$request->getSession();
        $userId=(int)$session->get('tn_auth_user_id',0);
        $organization=trim((string)$session->get('cos_organization_id',''));
        if ($userId<=0 || $organization==='') throw new CustomUserMessageAuthenticationException('Manager authorization required.');
        return new SelfValidatingPassport(new UserBadge($userId.'|'.rawurlencode($organization)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response { return null; }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if (self::isWebPath($request->getPathInfo())) return new RedirectResponse('/auth/login');
        return str_starts_with($request->getPathInfo(),'/api/spatial/') ? self::spatialUnauthorized() : self::forbidden();
    }

    public function start(Request $request, ?AuthenticationException $authException=null): Response
    {
        if (self::isWebPath($request->getPathInfo())) return new RedirectResponse('/auth/login');
        return str_starts_with($request->getPathInfo(),'/api/spatial/') ? self::spatialUnauthorized() : self::forbidden();
    }

    private static function isWebPath(string $path): bool
    {
        return str_starts_with($path,'/cabinet')
            || str_starts_with($path,'/sales')
            || str_starts_with($path,'/cos/architecture')
            || str_starts_with($path,'/admin/diagnostics')
            || str_starts_with($path,'/admin/content')
            || str_starts_with($path,'/diagnostics/')
            || str_starts_with($path,'/spatial')
            || str_starts_with($path,'/dev');
    }

    private static function spatialUnauthorized(): JsonResponse { return new JsonResponse(['ok'=>false,'message'=>'Unauthorized.'],Response::HTTP_UNAUTHORIZED); }
    private static function forbidden(): JsonResponse { return new JsonResponse(['ok'=>false,'error'=>'Manager authorization required.'],Response::HTTP_FORBIDDEN); }
}
