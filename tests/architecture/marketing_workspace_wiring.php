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
// Root Composer does not autoload the Symfony App namespace; its separate
// composer.json does. Architecture tests inspect source without booting Symfony.
foreach([
 'MarketingCampaignCreateAdapter.php',
 'MarketingCampaignSearchAdapter.php',
 'MarketingPaidAccountsAdapter.php',
] as $filename){
 $path=$root.'/symfony/src/Infrastructure/Capability/'.$filename;
 $assert(is_file($path),'Capability adapter source missing: '.$filename);
 $source=file_get_contents($path);
 $assert(str_contains($source,'CapabilityInvocationAdapterInterface'),
  'Capability adapter must use canonical Gateway: '.$filename);
}
$assert(is_file($root.'/symfony/templates/experience/marketing/workspace.html.twig'),'Workspace Twig missing.');
echo "marketing_workspace_wiring OK\n";
