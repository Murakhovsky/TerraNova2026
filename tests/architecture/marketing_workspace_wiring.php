<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

$root=dirname(__DIR__,2);
$services=file_get_contents($root.'/symfony/config/services.yaml');
$routes=file_get_contents($root.'/symfony/config/routes.yaml');
$manifest=require $root.'/app/Domains/Marketing/module.php';

$assert=static function(bool $value,string $label):void {
 if(!$value)throw new RuntimeException('Marketing wiring failure: '.$label);
};
foreach([
 'marketingDomainModule:','marketingNavigationContributor:',
 'Domains\Marketing\Infrastructure\Persistence\MySqlMarketingStore:',
 'Domains\Marketing\Infrastructure\Meta\MetaReadTransportInterface:',
 'App\Web\Marketing\MarketingPageController:',
] as $required)$assert(str_contains($services,$required),$required);
foreach([
 'cos_marketing_overview:','cos_marketing_campaign_create:',
 'cos_marketing_account_connect:','cos_marketing_account_sync:',
 'cos_marketing_insights_sync:',
] as $required)$assert(str_contains($routes,$required),$required);
foreach(['activate','budget.update','campaign.publish','ads.create'] as $blocked) {
 foreach($manifest['contributions']['capabilities'] as $cap) {
  $assert(!str_contains($cap,$blocked),'Paid write capability advertised: '.$blocked);
 }
}
foreach([
 'App\Infrastructure\Capability\MarketingCampaignCreateAdapter',
 'App\Infrastructure\Capability\MarketingCampaignSearchAdapter',
 'App\Infrastructure\Capability\MarketingPaidAccountsAdapter',
] as $class)$assert(class_exists($class),'Capability adapter not autoloaded: '.$class);
$assert(is_file($root.'/symfony/templates/experience/marketing/workspace.html.twig'),'Workspace Twig missing.');
echo "marketing_workspace_wiring OK\n";
