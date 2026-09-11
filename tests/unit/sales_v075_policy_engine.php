<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Rule\Service\ConditionEvaluator;

$engine = new PolicyEngine(new ConditionEvaluator());
$organizationId = 'test';
$policies = [
    new ActionPolicy(
        'high', $organizationId, 'sales.send_message',
        [['field' => 'deal.value', 'operator' => '>=', 'value' => 10000]],
        PolicyDecision::ApprovalRequired, 10, 'High value',
        'High-value outbound message requires manager approval.',
    ),
    new ActionPolicy(
        'low', $organizationId, 'sales.send_message',
        ['all' => [
            ['field' => 'deal.value', 'operator' => '<', 'value' => 10000],
            ['field' => 'risk.level', 'operator' => '!=', 'value' => 'HIGH'],
        ]],
        PolicyDecision::Auto, 20, 'Low value',
        'Low-risk message may run automatically.',
    ),
];

$low = $engine->evaluate('sales.send_message', ['deal' => ['value' => 9000], 'risk' => ['level' => 'LOW']], $policies);
if ($low->decision !== PolicyDecision::Auto) throw new RuntimeException('Expected AUTO for low-value low-risk message.');

$high = $engine->evaluate('sales.send_message', ['deal' => ['value' => 12000], 'risk' => ['level' => 'LOW']], $policies);
if ($high->decision !== PolicyDecision::ApprovalRequired) throw new RuntimeException('Expected approval for high-value message.');

$human = $engine->evaluate('sales.change_stage', ['target_stage' => ['code' => 'WON']], [
    new ActionPolicy(
        'won', $organizationId, 'sales.change_stage',
        [['field' => 'target_stage.code', 'operator' => '=', 'value' => 'WON']],
        PolicyDecision::HumanOnly, 1, 'Won is human only',
        'Closing WON requires an authorized human actor.',
    ),
]);
if ($human->decision !== PolicyDecision::HumanOnly) throw new RuntimeException('Expected HUMAN_ONLY for WON.');
if (!str_contains($human->reason, 'authorized human')) throw new RuntimeException('Policy reason must be surfaced.');

$none = $engine->evaluate('sales.send_message', ['deal' => ['value' => 500]], []);
if ($none->decision !== PolicyDecision::Denied) throw new RuntimeException('Kernel policy engine must remain fail-closed when no policy matches.');

echo "Sales V0.7.5 policy engine contract: OK\n";
