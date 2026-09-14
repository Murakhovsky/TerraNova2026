<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Automation\Rule\SalesRuleDefinitionCatalog;

$catalog = new SalesRuleDefinitionCatalog();
$definition = $catalog->normalize([
    'name' => 'No activity 48h → Intelligence → Follow-up',
    'trigger_key' => 'no_activity_detected',
    'condition_mode' => 'all',
    'conditions' => [
        ['field' => 'deal.days_without_activity', 'operator' => '>=', 'value' => 48, 'unit' => 'hours'],
        ['field' => 'deal.stage_code', 'operator' => 'NOT_IN', 'value' => ['LOST', 'WON']],
    ],
    'actions' => [
        ['type' => 'agent.run.sales_intelligence'],
        ['type' => 'sales.create_followup', 'parameters' => ['title' => 'Follow up', 'due_in_minutes' => 60]],
    ],
]);

if ($definition['trigger_type'] !== 'sales.no_activity_detected') throw new RuntimeException('Canonical trigger was not resolved.');
if (count($definition['actions']) !== 2) throw new RuntimeException('Multi-action THEN chain was not preserved.');
if (abs((float) $definition['conditions']['all'][0]['value'] - 2.0) > 0.0001) throw new RuntimeException('48 hours must normalize to 2 days.');
if ($definition['actions'][0]['type'] !== 'agent.run.sales_intelligence') throw new RuntimeException('Sales Intelligence action was not preserved.');
if ($definition['actions'][1]['type'] !== 'sales.create_followup') throw new RuntimeException('Follow-up action was not preserved.');

$reject = static function (callable $operation, string $label): void {
    try { $operation(); } catch (DomainException) { return; }
    throw new RuntimeException('Expected rejection: ' . $label);
};
$reject(fn () => $catalog->normalize(['name'=>'x','trigger_key'=>'sql.drop','conditions'=>[],'actions'=>[['type'=>'sales.create_task']]]), 'arbitrary trigger');
$reject(fn () => $catalog->normalize(['name'=>'x','trigger_key'=>'deal_created','conditions'=>[['field'=>'deal.password','operator'=>'=','value'=>'x']],'actions'=>[['type'=>'sales.create_task']]]), 'arbitrary fact');
$reject(fn () => $catalog->normalize(['name'=>'x','trigger_key'=>'deal_created','conditions'=>[],'actions'=>[['type'=>'kernel.delete_everything']]]), 'arbitrary action');
$reject(fn () => $catalog->normalize(['definition'=>['name'=>'x','trigger_key'=>'deal_created','conditions'=>[],'actions'=>[['type'=>'sales.create_task']],'sql'=>'DROP TABLE cos_rules']]), 'advanced unknown key');

$triggerTypes = array_column($catalog->catalog()['triggers'], 'canonical', 'key');
if (($triggerTypes['deal_stuck'] ?? null) !== 'sales.deal.stuck') throw new RuntimeException('Deal Stuck must expose a canonical Sales signal type.');

echo "Sales V0.7.3 Business Rule Editor unit contract passed.\n";
