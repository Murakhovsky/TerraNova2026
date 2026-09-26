<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$template=(string)file_get_contents($root.'/app/Interfaces/Web/View/growth/collectors.phtml');
$js=(string)file_get_contents($root.'/frontend/features/growth/workspace.js');

function expectGrowthV0270(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

expectGrowthV0270(substr_count($template,'data-growth-signal-feed-create')===1,'Signal Feed create form must be unique.');
expectGrowthV0270(str_contains($template,"name=\"enabled\" type=\"checkbox\" checked"),'Signal Feed workspace must default new feeds to enabled.');
expectGrowthV0270(str_contains($template,'data-action="<?php echo $enabled?\'disable\':\'enable\'; ?>"'),'Signal Feed toggle action must reflect persisted state.');
expectGrowthV0270(str_contains($js,"enabled:values.get('enabled')!==null"),'Signal Feed frontend must send explicit boolean enabled state.');
expectGrowthV0270(str_contains($js,"confidence<0||confidence>1"),'Signal Feed frontend confidence guard is missing.');
expectGrowthV0270(str_contains($js,"['enable','disable'].includes(action)"),'Signal Feed toggle action allowlist is missing.');
expectGrowthV0270(str_contains($js,"delete form.dataset.idempotencyKey"),'Signal Feed frontend must rotate idempotency key after successful mutation.');

echo "Growth V0.27 Signal Feed Workspace contracts passed.\n";
