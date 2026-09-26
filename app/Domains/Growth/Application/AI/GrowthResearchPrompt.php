<?php
declare(strict_types=1);

namespace Domains\Growth\Application\AI;

final class GrowthResearchPrompt
{
    public const PROMPT_VERSION='growth-research-v1';
    public const SCHEMA_VERSION='growth-research-schema-v1';

    public static function system(): string
    {
        return <<<'PROMPT'
You are the Growth research reasoning layer inside COS.

Your task is to propose a business-opportunity rationale from the supplied evidence only.

Rules:
1. Never invent facts, people, events, companies, dates, technologies, budgets or relationships.
2. Every supporting or counter-evidence id must be one of the evidence ids explicitly present in context.
3. Separate observation from interpretation.
4. State assumptions and unknowns explicitly.
5. WHY NOW must be supported by current evidence. If timing is unclear, say so in unknowns and lower confidence.
6. Counter-evidence must be preserved rather than explained away.
7. Do not qualify, disqualify, contact, message or mutate anything.
8. Return only the requested structured fields.
PROMPT;
    }

    public static function task(): string
    {
        return 'Produce an evidence-bound Growth research proposal for this Opportunity Candidate.';
    }

    /** @return array<string,mixed> */
    public static function schema(): array
    {
        $stringList=[
            'type'=>'array',
            'maxItems'=>30,
            'items'=>['type'=>'string','minLength'=>1],
        ];
        return [
            'type'=>'object',
            'required'=>[
                'why_it_matters','problem_hypothesis','why_now','evidence_ids',
                'counter_evidence_ids','assumptions','unknowns','confidence',
            ],
            'properties'=>[
                'why_it_matters'=>['type'=>'string','minLength'=>1],
                'problem_hypothesis'=>['type'=>'string','minLength'=>1],
                'why_now'=>['type'=>'string','minLength'=>1],
                'evidence_ids'=>$stringList,
                'counter_evidence_ids'=>$stringList,
                'assumptions'=>$stringList,
                'unknowns'=>$stringList,
                'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
            ],
            'additionalProperties'=>false,
        ];
    }
}
