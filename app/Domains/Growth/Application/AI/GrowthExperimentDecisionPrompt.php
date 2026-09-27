<?php
declare(strict_types=1);

namespace Domains\Growth\Application\AI;

use Domains\Growth\Domain\ExperimentDecisionType;

final class GrowthExperimentDecisionPrompt
{
    public const PROMPT_VERSION='growth-experiment-decision-v1';
    public const SCHEMA_VERSION='growth-experiment-decision-schema-v1';

    public static function system():string
    {
        return <<<'PROMPT'
You are the governed Growth experiment decision reasoning layer inside COS.

Recommend exactly one experiment conclusion using only the supplied deterministic attribution evidence.

Rules:
1. decision_type must be one of the allowed decision types.
2. evidence_ids must be a non-empty subset of allowed_evidence_ids.
3. promoted_variant_key is required only for promote_variant and must be one of allowed_variant_keys.
4. For iterate, continue, stop or inconclusive, promoted_variant_key must be null.
5. Never calculate replacement metrics from raw records. Use the supplied attribution metrics as authoritative.
6. Never claim statistical significance unless the context explicitly provides a statistical test. It does not by default.
7. Do not infer causality from correlation.
8. If sample is sparse, contradictory or too weak, prefer continue, iterate or inconclusive and lower confidence.
9. promote_variant is only a recommendation for human review. Do not activate, execute, archive or mutate the experiment.
10. Keep risks and assumptions explicit.
11. Return only the requested structured fields.
PROMPT;
    }

    public static function task():string
    {
        return 'Recommend one evidence-bound Growth experiment decision.';
    }

    /** @return array<string,mixed> */
    public static function schema():array
    {
        $strings=['type'=>'array','maxItems'=>30,'items'=>['type'=>'string','minLength'=>1]];
        return [
            'type'=>'object',
            'required'=>[
                'decision_type','promoted_variant_key','rationale',
                'evidence_ids','risks','assumptions','confidence',
            ],
            'properties'=>[
                'decision_type'=>['type'=>'string','enum'=>ExperimentDecisionType::values()],
                'promoted_variant_key'=>['type'=>['string','null']],
                'rationale'=>['type'=>'string','minLength'=>1],
                'evidence_ids'=>$strings,
                'risks'=>$strings,
                'assumptions'=>$strings,
                'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
            ],
            'additionalProperties'=>false,
        ];
    }
}
