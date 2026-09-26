<?php
declare(strict_types=1);

namespace Domains\Growth\Application\AI;

final class GrowthAutonomousContentPrompt
{
    public const PROMPT_VERSION='growth-autonomous-content-v1';
    public const SCHEMA_VERSION='growth-autonomous-content-schema-v1';
    public const RISK_FLAGS=[
        'unsupported_claim',
        'sensitive_internal',
        'compliance_unknown',
        'excessive_pressure',
        'missing_context',
        'hallucination_risk',
        'internal_reference_leak',
        'delivery_identity_leak',
    ];

    public static function system():string
    {
        return <<<'PROMPT'
You write the final outbound Growth message or call brief for COS.

Rules:
1. Use only facts present in the supplied context. Never invent company facts, relationships, budgets, results, case studies, prices, deadlines, urgency or prior conversations.
2. Follow the selected recommendation's channel, action, contact and message angle. Do not change the strategic action.
3. The final body must never mention internal candidate IDs, recommendation IDs, signal/evidence IDs, scores, confidence values, policy names, COS internals or hidden reasoning.
4. Do not include contact delivery identities such as email addresses, phone numbers or profile URLs.
5. Keep the copy respectful and specific. Do not use coercive pressure, fake scarcity, deceptive familiarity or unsupported social proof.
6. For email and LinkedIn, return sendable message text. For phone, return a concise call brief/talking points, not a fabricated transcript.
7. evidence_ids must be a non-empty subset of allowed_evidence_ids and must support the actual claims in the body.
8. risk_flags must include every applicable risk from the allowed list. If context is insufficient for safe personalization, include missing_context. Never hide uncertainty to make approval easier.
9. confidence reflects confidence that every factual claim in the body is supported by supplied context.
10. Return only the requested structured fields.
PROMPT;
    }

    public static function task():string
    {
        return 'Draft evidence-bound outreach content for the supplied Growth engagement recommendation.';
    }

    /** @return array<string,mixed> */
    public static function schema():array
    {
        return [
            'type'=>'object',
            'required'=>['body','evidence_ids','risk_flags','confidence'],
            'properties'=>[
                'body'=>['type'=>'string','minLength'=>1,'maxLength'=>10000],
                'evidence_ids'=>[
                    'type'=>'array','minItems'=>1,'maxItems'=>30,'uniqueItems'=>true,
                    'items'=>['type'=>'string','minLength'=>1],
                ],
                'risk_flags'=>[
                    'type'=>'array','maxItems'=>20,'uniqueItems'=>true,
                    'items'=>['type'=>'string','enum'=>self::RISK_FLAGS],
                ],
                'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
            ],
            'additionalProperties'=>false,
        ];
    }
}
