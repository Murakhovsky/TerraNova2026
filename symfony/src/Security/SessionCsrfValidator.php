<?php
declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

final readonly class SessionCsrfValidator
{
    public function isValid(Request $request): bool
    {
        if (!$request->hasSession()) return false;
        $expected=trim((string)$request->getSession()->get('cos_csrf_token',''));
        if ($expected==='') return false;

        $provided=trim((string)$request->headers->get('X-CSRF-Token',''));
        if ($provided==='') $provided=trim((string)$request->request->get('csrf_token',''));
        if ($provided==='' && str_contains(strtolower((string)$request->headers->get('Content-Type','')),'application/json')) {
            $decoded=json_decode((string)$request->getContent(),true);
            if (is_array($decoded)) $provided=trim((string)($decoded['csrf_token']??''));
        }
        return $provided!=='' && hash_equals($expected,$provided);
    }

    public function token(Request $request): string
    {
        return $request->hasSession() ? trim((string)$request->getSession()->get('cos_csrf_token','')) : '';
    }
}
