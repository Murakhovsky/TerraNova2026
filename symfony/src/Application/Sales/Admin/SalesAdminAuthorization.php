<?php
declare(strict_types=1);

namespace App\Application\Sales\Admin;

use Domains\Sales\Application\Contract\SalesAccessControlInterface;

final readonly class SalesAdminAuthorization
{
    public const PIPELINES = 'sales.admin.pipeline.manage';
    public const RULES = 'sales.admin.rules.manage';
    public const AGENTS = 'sales.admin.agents.manage';
    public const POLICIES = 'sales.admin.policies.manage';
    public const TEAMS = 'sales.admin.teams.manage';
    public const INTEGRATIONS = 'sales.admin.integrations.manage';
    public const AUDIT = 'sales.admin.audit.view';

    public function __construct(private SalesAccessControlInterface $access)
    {
    }

    public function allows(string $organizationId, int $userId, string $capability): bool
    {
        return $this->access->hasCapability($organizationId, $userId, $capability);
    }
}
