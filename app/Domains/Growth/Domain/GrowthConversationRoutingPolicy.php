<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

final readonly class GrowthConversationRoutingPolicy
{
    public const POLICY_VERSION='growth-conversation-routing-v1';
    public const MIN_CONFIDENCE=0.75;

    /** @return array{route:GrowthConversationRoute,reason:string} */
    public function decide(
        GrowthResponseIntent $intent,
        GrowthResponseNextOwner $recommendedOwner,
        float $confidence,
        bool $hasContact,
    ):array {
        if($confidence<0.0||$confidence>1.0){
            return ['route'=>GrowthConversationRoute::HumanReview,'reason'=>'Classification confidence is outside the valid range.'];
        }
        if($confidence<self::MIN_CONFIDENCE||$recommendedOwner===GrowthResponseNextOwner::HumanReview){
            return ['route'=>GrowthConversationRoute::HumanReview,'reason'=>'Classification is not authoritative enough for automatic routing.'];
        }

        if($intent===GrowthResponseIntent::Unsubscribe){
            return $hasContact
                ?['route'=>GrowthConversationRoute::Suppression,'reason'=>'Explicit unsubscribe must suppress future Growth outreach.']
                :['route'=>GrowthConversationRoute::HumanReview,'reason'=>'Unsubscribe was detected but no authoritative Growth contact is attached.'];
        }
        if($intent===GrowthResponseIntent::NotInterested){
            return ['route'=>GrowthConversationRoute::NoAction,'reason'=>'Explicit decline closes the conversation without another outreach action.'];
        }

        return match($recommendedOwner){
            GrowthResponseNextOwner::Sales=>[
                'route'=>GrowthConversationRoute::Sales,
                'reason'=>'Commercial response is routed to the Sales application boundary.',
            ],
            GrowthResponseNextOwner::Service=>[
                'route'=>GrowthConversationRoute::Service,
                'reason'=>'Service/support response is routed to the Service application boundary.',
            ],
            GrowthResponseNextOwner::Partnership=>[
                'route'=>GrowthConversationRoute::Partnership,
                'reason'=>'Partnership ownership is authoritative, but remains in the Growth routing queue until a Partnership adapter exists.',
            ],
            GrowthResponseNextOwner::Growth=>[
                'route'=>GrowthConversationRoute::Growth,
                'reason'=>'Growth retains ownership for research or prospect-development follow-up.',
            ],
            GrowthResponseNextOwner::HumanReview=>[
                'route'=>GrowthConversationRoute::HumanReview,
                'reason'=>'Human review was explicitly requested by classification.',
            ],
        };
    }
}
