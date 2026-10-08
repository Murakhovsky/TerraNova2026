<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$ui = $root . '/symfony/src/Web/Experience/';
foreach ([
    'Action/UIActionIntent.php', 'Action/UIActionDangerLevel.php',
    'Action/UIActionPlacement.php', 'Action/UIActionConfirmation.php', 'Action/UIAction.php',
    'Adaptive/ExperienceMode.php', 'Adaptive/ExperienceContext.php',
    'Adaptive/ExperienceComposition.php', 'Adaptive/AdaptiveExperienceResolver.php',
] as $file) {
    require_once $ui . $file;
}

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionIntent;
use App\Web\Experience\Adaptive\AdaptiveExperienceResolver;
use App\Web\Experience\Adaptive\ExperienceContext;
use App\Web\Experience\Adaptive\ExperienceMode;

$sections = [
    ['id' => 'goal.summary', 'level' => 0],
    ['id' => 'execution.timeline', 'level' => 1],
    ['id' => 'evidence.details', 'level' => 2],
    ['id' => 'domain.expert', 'level' => 3, 'capability' => 'sales.workspace'],
    ['id' => 'risk.warning', 'level' => 3, 'critical' => true],
];
$actions = [
    new UIAction('sales.lead.view', 'View', UIActionIntent::View, priority: 20),
    new UIAction('sales.lead.update', 'Update', UIActionIntent::Edit, permission: 'sales.lead.write', priority: 10),
    new UIAction('sales.lead.insecure', 'Insecure mutation', UIActionIntent::Execute, command: 'SalesWrite', priority: 5),
];
$resolver = new AdaptiveExperienceResolver();
$make = static fn (ExperienceMode $mode, array $expanded = [], array $capabilities = []): ExperienceContext
    => new ExperienceContext('user-1', 'tenant-1', $mode, $sections, $capabilities, $expanded,
        [['id' => 'approval-1']], [['id' => 'risk-1']]);
$result = $resolver->compose($make(ExperienceMode::Result), $actions)->toArray();
$process = $resolver->compose($make(ExperienceMode::Process), $actions)->toArray();
$expert = $resolver->compose($make(ExperienceMode::Expert, [], ['sales.workspace']), $actions)->toArray();

if ($result['visible_components'] !== ['goal.summary', 'risk.warning']
    || $process['visible_components'] !== ['goal.summary', 'execution.timeline', 'risk.warning']
    || $expert['visible_components'] !== ['goal.summary', 'execution.timeline', 'evidence.details', 'domain.expert', 'risk.warning']) {
    throw new RuntimeException('Mode disclosure boundaries regressed.');
}
if ($result['decision_requests'] !== [['id' => 'approval-1']]
    || $result['blocking_risks'] !== [['id' => 'risk-1']]) {
    throw new RuntimeException('Critical decisions/risks hidden in Result View.');
}
foreach ([$result, $process, $expert] as $composed) {
    if (in_array('sales.lead.insecure', [...$composed['primary_actions'], ...$composed['secondary_actions']], true)) {
        throw new RuntimeException('Expert mode granted missing mutation permission.');
    }
}
$expanded = $resolver->compose($make(ExperienceMode::Result, ['evidence.details']), $actions)->toArray();
if (!in_array('evidence.details', $expanded['visible_components'], true)
    || $expanded['disclosure_reasons']['evidence.details'] !== 'explicit_expansion') {
    throw new RuntimeException('Explicit user expansion lost.');
}
$hidden = $resolver->compose($make(ExperienceMode::Result, ['domain.expert']), $actions)->toArray();
if (in_array('domain.expert', $hidden['visible_components'], true)
    || $hidden['disclosure_reasons']['domain.expert'] !== 'capability_unavailable') {
    throw new RuntimeException('User expansion bypassed module capability availability.');
}

echo "Federation adaptive disclosure presentation invariants passed.\n";
