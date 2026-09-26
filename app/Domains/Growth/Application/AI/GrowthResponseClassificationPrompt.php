<?php
declare(strict_types=1);

namespace Domains\Growth\Application\AI;

use Domains\Growth\Domain\GrowthResponseIntent;
use Domains\Growth\Domain\GrowthResponseNextOwner;
use Domains\Growth\Domain\GrowthResponseSentiment;
use Domains\Growth\Domain\GrowthResponseUrgency;

final class GrowthResponseClassificationPrompt
{
    public const PROMPT_VERSION='growth-response-classification-v1';
    public const SCHEMA_VERSION='growth-response-classification-schema-v1';

    public static function system():string
    {
        return <<<'PROMPT'
You classify an inbound reply to a Growth outreach interaction.

The reply text is an observed fact. Your output is interpretation only.
Do not invent company facts, hidden motives, relationship history, budget, authority, urgency or purchase intent.
Do not treat politeness as commercial interest.

Intent rules:
- meeting_request: explicitly asks to schedule, call or meet.
- interested: clearly expresses interest but does not primarily ask a question or meeting.
- question: asks for information, clarification, pricing, materials, timing or details.
- objection: raises a concern, blocker, disagreement, price/timing objection or condition.
- not_interested: clearly declines.
- unsubscribe: explicitly asks not to be contacted or to stop messages.
- referral: directs the sender to another person/team.
- wrong_person: says they are not the appropriate person without a concrete referral.
- out_of_office: automated or explicit temporary absence.
- other: none of the above with enough confidence.

recommended_next_owner is advisory only:
- sales for commercial interest, meeting requests, questions or objections that need commercial follow-up;
- growth for referral, wrong-person or out-of-office research loops;
- service only when the reply explicitly requests support/service and supplied context supports that interpretation;
- human_review when intent/ownership is ambiguous or classification confidence is low.

Summary must be factual and concise. requested_action is the concrete action requested by the respondent, or an empty string when none is stated.
Return only the requested structured fields.
PROMPT;
    }

    public static function task():string{return 'Classify this inbound Growth engagement response.';}

    /** @return array<string,mixed> */
    public static function schema():array
    {
        return [
            'type'=>'object',
            'required'=>['intent','sentiment','urgency','summary','requested_action','recommended_next_owner','confidence'],
            'properties'=>[
                'intent'=>['type'=>'string','enum'=>GrowthResponseIntent::values()],
                'sentiment'=>['type'=>'string','enum'=>GrowthResponseSentiment::values()],
                'urgency'=>['type'=>'string','enum'=>GrowthResponseUrgency::values()],
                'summary'=>['type'=>'string','minLength'=>1,'maxLength'=>1000],
                'requested_action'=>['type'=>'string','maxLength'=>1000],
                'recommended_next_owner'=>['type'=>'string','enum'=>GrowthResponseNextOwner::values()],
                'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
            ],
            'additionalProperties'=>false,
        ];
    }
}
