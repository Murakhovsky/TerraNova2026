<?php
declare(strict_types=1);

namespace Interfaces\Web\Security;

final readonly class CsrfTokenManager
{
    private const SESSION_KEY = 'cos_csrf_token';

    public function __construct(private mixed $session)
    {
    }

    public function token(): string
    {
        $token = (string) ($this->session->get(self::SESSION_KEY) ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public function verify(?string $provided): bool
    {
        return is_string($provided) && $provided !== '' && hash_equals($this->token(), $provided);
    }
}
