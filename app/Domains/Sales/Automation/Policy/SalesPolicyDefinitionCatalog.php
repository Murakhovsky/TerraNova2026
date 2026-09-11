<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Policy;

use DomainException;

final class SalesPolicyDefinitionCatalog
{
    private const DECISIONS = ['AUTO','APPROVAL_REQUIRED','DENIED','HUMAN_ONLY'];
    private const ROLES = ['AGENT','RULE','SYSTEM','INTEGRATION','MANAGER','ADMIN'];
    private const RISKS = ['LOW'=>1,'MEDIUM'=>2,'HIGH'=>3,'CRITICAL'=>4];
    private const OPERATORS = ['=','!=','>','>=','<','<=','IN','NOT_IN','EXISTS','NOT_EXISTS'];
    private const FIELDS = [
        'actor.role','deal.pipeline_id','deal.stage_code','deal.value','risk.level','risk.rank',
        'deal.source','deal.team_id','confidence','target_stage.code','target_stage.is_won','target_stage.is_lost',
    ];

    public function catalog(): array
    {
        return [
            'decisions' => [
                ['value'=>'AUTO','label'=>'AUTO','description'=>'Automation may proceed without manual approval when all other policies allow.'],
                ['value'=>'APPROVAL_REQUIRED','label'=>'Approval required','description'=>'Kernel creates an Approval and blocks execution until approved.'],
                ['value'=>'DENIED','label'=>'Denied','description'=>'Automation execution is forbidden and the reason is surfaced.'],
                ['value'=>'HUMAN_ONLY','label'=>'Human only','description'=>'AI may recommend the action, but automation cannot create an execution path.'],
            ],
            'roles' => self::ROLES,
            'risks' => array_keys(self::RISKS),
            'operators' => self::OPERATORS,
            'fields' => self::FIELDS,
        ];
    }

    public function normalize(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') throw new DomainException('Policy name is required.');
        $decision = strtoupper(trim((string) ($input['decision'] ?? $input['execution_mode'] ?? '')));
        if (!in_array($decision, self::DECISIONS, true)) throw new DomainException('Unsupported policy decision.');
        $enabled = filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $enabled ??= true;
        $conditions = [];

        $roles = $this->strings($input['allowed_roles'] ?? []);
        foreach ($roles as $role) if (!in_array($role, self::ROLES, true)) throw new DomainException('Unsupported actor role: ' . $role);
        if ($roles !== []) $conditions[] = ['field'=>'actor.role','operator'=>'IN','value'=>$roles];

        foreach (['pipeline_id'=>'deal.pipeline_id','stage_code'=>'deal.stage_code','source'=>'deal.source','team_id'=>'deal.team_id','target_stage_code'=>'target_stage.code'] as $key=>$field) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') $conditions[] = ['field'=>$field,'operator'=>'=','value'=>$key === 'stage_code' || $key === 'target_stage_code' ? strtoupper($value) : $value];
        }
        if (($input['value_limit'] ?? '') !== '') {
            if (!is_numeric($input['value_limit'])) throw new DomainException('Value limit must be numeric.');
            $conditions[] = ['field'=>'deal.value','operator'=>$this->operator((string) ($input['value_operator'] ?? '<=')),'value'=>(float) $input['value_limit']];
        }
        $risk = strtoupper(trim((string) ($input['risk_limit'] ?? '')));
        if ($risk !== '') {
            if (!isset(self::RISKS[$risk])) throw new DomainException('Unsupported risk limit.');
            $conditions[] = ['field'=>'risk.rank','operator'=>'<=','value'=>self::RISKS[$risk]];
        }
        if (($input['confidence'] ?? '') !== '') {
            if (!is_numeric($input['confidence'])) throw new DomainException('Confidence must be numeric.');
            $confidence = (float) $input['confidence'];
            if ($confidence < 0 || $confidence > 1) throw new DomainException('Confidence must be between 0 and 1.');
            $conditions[] = ['field'=>'confidence','operator'=>$this->operator((string) ($input['confidence_operator'] ?? '>=')),'value'=>$confidence];
        }
        $extra = $input['conditions'] ?? [];
        if (is_string($extra) && trim($extra) !== '') {
            $extra = json_decode($extra, true, flags: JSON_THROW_ON_ERROR);
        }
        if ($extra !== [] && !is_array($extra)) throw new DomainException('Conditions must be a declarative JSON array.');
        if (is_array($extra) && $extra !== []) $conditions = [...$conditions, ...$this->validateConditions($extra)];

        $reason = trim((string) ($input['reason'] ?? $input['decision_reason'] ?? ''));
        return [
            'name' => mb_substr($name,0,220),
            'decision' => $decision,
            'enabled' => $enabled,
            'priority' => max(1,min(10000,(int) ($input['priority'] ?? 100))),
            'reason' => $reason === '' ? null : mb_substr($reason,0,500),
            'conditions' => $conditions,
        ];
    }

    private function validateConditions(array $conditions): array
    {
        if (!array_is_list($conditions)) throw new DomainException('Conditions must be a list.');
        $out=[];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) throw new DomainException('Malformed policy condition.');
            $field = trim((string) ($condition['field'] ?? ''));
            if (!in_array($field,self::FIELDS,true)) throw new DomainException('Unsupported policy fact: ' . $field);
            $operator = $this->operator((string) ($condition['operator'] ?? '='));
            $item=['field'=>$field,'operator'=>$operator];
            if (!in_array($operator,['EXISTS','NOT_EXISTS'],true)) $item['value']=$condition['value'] ?? null;
            $out[]=$item;
        }
        return $out;
    }

    private function operator(string $value): string
    {
        $value = strtoupper(str_replace(' ','_',trim($value)));
        if (!in_array($value,self::OPERATORS,true)) throw new DomainException('Unsupported policy operator: ' . $value);
        return $value;
    }

    private function strings(mixed $value): array
    {
        if (is_string($value)) $value = preg_split('/[\s,;]+/', strtoupper($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!is_array($value)) return [];
        return array_values(array_unique(array_filter(array_map(static fn($v)=>strtoupper(trim((string)$v)),$value))));
    }
}
