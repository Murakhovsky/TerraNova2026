<?php
declare(strict_types=1);

namespace App\Engineering\Application\Policy;

use App\Engineering\Domain\Agent\AgentRole;
use InvalidArgumentException;

final class AgentCapabilityRegistry
{
    /** @return array<string,mixed> */
    public function forRole(AgentRole $role): array
    {
        return match ($role) {
            AgentRole::ENGINEERING_MANAGER => $this->readOnly(['workflow_coordinate' => true]),
            AgentRole::PRODUCT_REQUIREMENTS => $this->readOnly(['requirements_write' => true]),
            AgentRole::QA_PLANNER => $this->readOnly(['test_plan_write' => true]),
            AgentRole::PRINCIPAL_ARCHITECT => $this->readOnly(['architecture_write' => true, 'docs_write' => true]),
            AgentRole::DEVELOPER => [
                'repository_read' => true,
                'repository_write' => 'branch_only',
                'test_write' => true,
                'commit' => true,
                'pull_request' => true,
                'merge' => false,
                'production' => false,
                'requirements_write' => false,
                'architecture_write' => false,
            ],
            AgentRole::REVIEWER => $this->readOnly(['review' => true]),
            AgentRole::QA_EXECUTOR, AgentRole::QA => $this->readOnly(['test_write' => true, 'qa_execute' => true]),
            AgentRole::INTEGRATION_RELEASE => $this->readOnly(['integration_assess' => true, 'release_assess' => true]),
        };
    }

    public function allows(AgentRole $role, string $capability): bool
    {
        $profile = $this->forRole($role);
        if (!array_key_exists($capability, $profile)) throw new InvalidArgumentException('Unknown Engineering agent capability '.$capability.'.');
        return $profile[$capability] === true || $profile[$capability] === 'branch_only';
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function readOnly(array $extra = []): array
    {
        return array_merge([
            'repository_read' => true,
            'repository_write' => false,
            'test_write' => false,
            'commit' => false,
            'pull_request' => false,
            'merge' => false,
            'production' => false,
            'requirements_write' => false,
            'architecture_write' => false,
        ], $extra);
    }
}
