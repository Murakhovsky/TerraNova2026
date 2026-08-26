<?php
declare(strict_types=1);

namespace Kernel\Tenant;

interface OrganizationContextInterface
{
    public function id(): string;

    public function actorId(): string;

    public function isAuthenticated(): bool;
}
