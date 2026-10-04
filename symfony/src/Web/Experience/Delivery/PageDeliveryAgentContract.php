<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

use App\Engineering\Domain\Agent\AgentRole;

final class PageDeliveryAgentContract
{
    /** @return array<string,array<string,mixed>> */
    public function roles(): array
    {
        return [
            AgentRole::ENGINEERING_MANAGER->value => [
                'responsibility' => 'Convert Page Contract and product intent into a bounded Feature Specification and delivery plan.',
                'must_preserve' => ['page_id','route','domain','capability','priority','primary_goal','human_gate'],
            ],
            AgentRole::PRINCIPAL_ARCHITECT->value => [
                'responsibility' => 'Select canonical Experience architecture without creating parallel frontend or Domain UI infrastructure.',
                'must_preserve' => ['archetype','patterns','states','responsive','security_boundaries'],
            ],
            AgentRole::DEVELOPER->value => [
                'responsibility' => 'Implement the registered page using canonical Experience components and the approved architecture.',
                'must_produce' => ['changed_files','tests_run','acceptance_criteria_evidence','pull_request'],
            ],
            AgentRole::REVIEWER->value => [
                'responsibility' => 'Review the exact implementation revision for correctness, architecture, security and Experience contract compliance.',
                'must_verify' => ['page_contract','architecture','changed_files','ci_revision','blocking_findings'],
            ],
            AgentRole::QA->value => [
                'responsibility' => 'Verify observable page behavior and required Experience quality gates on the reviewed revision.',
                'must_verify' => ['functional','browser','desktop','mobile','overflow','keyboard','accessibility','browser_errors','screenshots'],
            ],
            'HUMAN' => [
                'responsibility' => 'Make the product/UX acceptance decision after automated gates pass.',
                'exclusive_authority' => ['human_acceptance','V1_READY promotion decision'],
            ],
        ];
    }
}
