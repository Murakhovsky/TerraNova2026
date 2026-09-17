<?php
declare(strict_types=1);

namespace Kernel\Tenant\Model;

final class TenantPermissions
{
    public const ACCESS = 'cos.tenant.access';
    public const MANAGE = 'cos.tenant.manage';
    public const ADMIN = 'cos.tenant.admin';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACCESS, self::MANAGE, self::ADMIN];
    }

    private function __construct()
    {
    }
}
