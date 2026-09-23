<?php
declare(strict_types=1);

namespace Domains\Growth\Application\AI;

use Domains\Growth\Domain\OptimizationTargetType;

final class GrowthOptimizationPrompt
{
    public const PROMPT_VERSION='growth-learning-optimization-v1';
    public const SCHEMA_VERSION='growth-learning-optimization-schema-v1';

    public static function system():string
    {
        return <<<'PROMPT'
You are the Growth learning optimization reasoning layer inside COS.

Recommend exactly one controlled optimization based only on the supplied learning evidence.

Rules:
1. target_type, target_id and base_revision must identify one item from active_targets.
2. evidence_ids must be a non-empty subset of allowed_evidence_ids.
3. proposed_criteria must be a complete replacement criteria object valid for the selected target type.
4. Keep changes conservative. Do not overfit weak or sparse evidence.
5. Preserve useful current criteria unless evidence supports changing them.
6. Never activate, execute or mutate the target. You only recommend a draft revision.
7. Distinguish rationale, risks and assumptions.
8. Do not infer causality from correlation. Phrase evidence accordingly.
9. If evidence is contradictory, prefer the smallest defensible change and lower confidence.
10. Return only the requested structured fields.

Target criteria shapes:
- icp_profile: industries[], regions[], min_employees|null, max_employees|null, technologies[], required_signal_types[]
- qualification_policy: qualify_minimums{fit,need,timing,access,value}, hard_reject_below{}, min_confidence
PROMPT;
    }

    public static function task():string
    {
        return 'Recommend one evidence-bound Growth optimization draft.';
    }

    /** @return array<string,mixed> */
    public static function schema():array
    {
        $strings=['type'=>'array','maxItems'=>30,'items'=>['type'=>'string','minLength'=>1]];
        return [
            'type'=>'object',
            'required'=>[
                'target_type','target_id','base_revision','proposed_name','proposed_criteria',
                'rationale','evidence_ids','risks','assumptions','confidence',
            ],
            'properties'=>[
                'target_type'=>['type'=>'string','enum'=>OptimizationTargetType::values()],
                'target_id'=>['type'=>'string','minLength'=>1],
                'base_revision'=>['type'=>'integer','minimum'=>1],
                'proposed_name'=>['type'=>'string','minLength'=>1],
                'proposed_criteria'=>['type'=>'object'],
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
