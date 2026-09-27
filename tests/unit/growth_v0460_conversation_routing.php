<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Domain\GrowthConversationRoute;
use Domains\Growth\Domain\GrowthConversationRoutingPolicy;
use Domains\Growth\Domain\GrowthResponseIntent;
use Domains\Growth\Domain\GrowthResponseNextOwner;

function expectGrowthV0460(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$policy=new GrowthConversationRoutingPolicy();

$decision=$policy->decide(GrowthResponseIntent::Unsubscribe,GrowthResponseNextOwner::Sales,0.98,true);
expectGrowthV0460($decision['route']===GrowthConversationRoute::Suppression,'Unsubscribe must override advisory Sales ownership.');

$decision=$policy->decide(GrowthResponseIntent::Unsubscribe,GrowthResponseNextOwner::Sales,0.98,false);
expectGrowthV0460($decision['route']===GrowthConversationRoute::HumanReview,'Unsubscribe without contact must not create an unusable suppression.');

$decision=$policy->decide(GrowthResponseIntent::MeetingRequest,GrowthResponseNextOwner::Sales,0.70,true);
expectGrowthV0460($decision['route']===GrowthConversationRoute::HumanReview,'Low-confidence model output must not route cross-domain.');

$decision=$policy->decide(GrowthResponseIntent::NotInterested,GrowthResponseNextOwner::Sales,0.99,true);
expectGrowthV0460($decision['route']===GrowthConversationRoute::NoAction,'Explicit decline must override advisory Sales ownership.');

$decision=$policy->decide(GrowthResponseIntent::MeetingRequest,GrowthResponseNextOwner::Sales,0.97,true);
expectGrowthV0460($decision['route']===GrowthConversationRoute::Sales,'Qualified Sales ownership must route to Sales.');

$decision=$policy->decide(GrowthResponseIntent::Question,GrowthResponseNextOwner::Service,0.93,true);
expectGrowthV0460($decision['route']===GrowthConversationRoute::Service,'Explicit Service ownership must route to Service.');

$decision=$policy->decide(GrowthResponseIntent::Other,GrowthResponseNextOwner::Partnership,0.94,true);
expectGrowthV0460($decision['route']===GrowthConversationRoute::Partnership,'Partnership ownership must enter the Partnership queue.');

$decision=$policy->decide(GrowthResponseIntent::WrongPerson,GrowthResponseNextOwner::Growth,0.96,true);
expectGrowthV0460($decision['route']===GrowthConversationRoute::Growth,'Growth research loop must remain in Growth.');

echo "Growth V0.46 Conversation Routing Policy contracts passed.\n";
