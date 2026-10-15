<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$assert=static function(bool $x,string $s):void{if(!$x)throw new RuntimeException($s);};
$schedule=(string)file_get_contents($root.'/symfony/src/Scheduler/CosScheduleProvider.php');
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
$handler=(string)file_get_contents($root.'/symfony/src/Application/CapitalMarkets/Handler/ReconcileBStocksPoolSubscriptionsHandler.php');
$service=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/BStocksPoolSubscriptionReconciler.php');
$cli=(string)file_get_contents($root.'/symfony/src/Command/CapitalMarketsBStocksOpportunityScanCommand.php');
foreach(['capitalMarketsPoolSubscriptionsEnabled','ReconcileBStocksPoolSubscriptions'] as $key)$assert(str_contains($schedule,$key),'Missing guarded pool schedule '.$key);
foreach(['COS_CM_POOL_SUBSCRIPTIONS_SCHEDULER_ENABLED','BStocksPoolSubscriptionReconciler'] as $key)$assert(str_contains($services,$key),'Missing pool wiring '.$key);
$assert(str_contains($handler,'$this->planner->reconcile'),'Scheduler must use same planner.');
foreach(['CrossVenueSubscriptionPlanner','BStocksReferenceBboPlanner','->apply('] as $key)$assert(str_contains($service,$key),'Auto plans must reuse current audited subscription boundary.');
foreach(['COS_CM_BSTOCKS_OPPORTUNITY_ENABLED','listForCanonicalDraft','TokenizedEquityUniverseScanner'] as $key)$assert(str_contains($cli,$key),'Opportunity scanner must use imported canonical seed universe.');
echo "Pool scheduling and Opportunity CLI architecture contracts passed.\n";
