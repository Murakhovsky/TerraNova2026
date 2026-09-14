<?php
declare(strict_types=1);

use Domains\Property\Model\PropertyWorkflowPolicy;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$policy = new PropertyWorkflowPolicy();
$draft = [
    'status' => 'draft',
    'operational_stage' => 'intake',
    'title' => 'Будинок',
    'slug' => 'budynok',
    'type_id' => 1,
    'location_id' => 1,
    'agent_id' => 7,
    'short_description' => 'Ціна за запитом',
    'description' => 'Детальний опис',
    'area_total' => 120,
    'meta_title' => 'Будинок у Львові',
    'meta_description' => 'Будинок для перевірки workflow policy.',
    'next_action_title' => 'Перевірити документи',
    'next_action_due_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
];

if (!$policy->readiness($draft, 1)['ready']) {
    throw new RuntimeException('Complete property must pass the publication checklist.');
}
if ($policy->readiness($draft, 0)['ready']) {
    throw new RuntimeException('Property without media must fail the publication checklist.');
}
if ($policy->nextStage('intake') !== 'verification') {
    throw new RuntimeException('Operational stage sequence is broken.');
}

$reserved = $policy->synchronizeStageWithStatus($draft + ['status_note' => 'Завдаток отримано']);
$reserved['status'] = 'reserved';
$reserved = $policy->synchronizeStageWithStatus($reserved);
if (($reserved['operational_stage'] ?? null) !== 'reserved') {
    throw new RuntimeException('Reserved status must synchronize the operational stage.');
}
if (!$policy->requiresStatusNote('sold') || $policy->requiresStatusNote('active')) {
    throw new RuntimeException('Status note policy is inconsistent.');
}

echo "Property workflow policy passed.\n";
