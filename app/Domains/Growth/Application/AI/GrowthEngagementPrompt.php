<?php
declare(strict_types=1);

namespace Domains\Growth\Application\AI;

use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\NextBestActionType;

final class GrowthEngagementPrompt
{
    public const PROMPT_VERSION='growth-engagement-v1';
    public const SCHEMA_VERSION='growth-engagement-schema-v1';

    public static function system():string
    {
        return <<<'PROMPT'
You are the Growth engagement reasoning layer inside COS.

Recommend exactly one next best action for the supplied Opportunity Candidate.

Rules:
1. Use only facts and evidence supplied in context. Never invent people, channels, events, budgets or relationships.
2. evidence_ids must be a non-empty subset of allowed_evidence_ids.
3. contact_id must be null or one of allowed_contact_ids. For direct-contact actions, use only a channel listed in that contact's available_channels.
4. Separate recommendation rationale from observed facts.
5. Preserve unknowns rather than filling gaps with assumptions.
6. Do not write outreach copy. message_angle is a concise strategic angle, not a final message.
7. Do not execute, contact, qualify, hand off or mutate anything.
8. Use only allowed action types and channels.
9. If evidence is too weak for outreach, prefer monitor or ignore and lower confidence.
10. Return only the requested structured fields.
PROMPT;
    }

    public static function task():string
    {
        return 'Recommend one evidence-bound Growth next best action.';
    }

    /** @return array<string,mixed> */
    public static function schema():array
    {
        $list=['type'=>'array','maxItems'=>30,'items'=>['type'=>'string','minLength'=>1]];
        return [
            'type'=>'object',
            'required'=>['action_type','channel','contact_id','rationale','message_angle','evidence_ids','unknowns','confidence'],
            'properties'=>[
                'action_type'=>['type'=>'string','enum'=>NextBestActionType::values()],
                'channel'=>['type'=>'string','enum'=>EngagementChannel::values()],
                'contact_id'=>['type'=>['string','null']],
                'rationale'=>['type'=>'string','minLength'=>1],
                'message_angle'=>['type'=>'string','minLength'=>1],
                'evidence_ids'=>$list,
                'unknowns'=>$list,
                'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
            ],
            'additionalProperties'=>false,
        ];
    }
}
