<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

use InvalidArgumentException;

/**
 * Foundation-only, versioned presentation metadata for Workspaces, UIActions
 * and components. It is NEVER an entitlement or runtime command binding.
 */
final readonly class ExperienceSemantic
{
    public const SCHEMA_VERSION = '1.0.0';
    private const KINDS = ['workspace', 'ui_action', 'component'];
    private const ROLES = ['primary', 'supporting', 'contextual', 'expert'];
    private const RULES = ['mode_default', 'on_trigger', 'explicit', 'always'];
    private const TRIGGERS = [
        'approval_required', 'blocked', 'failed', 'running',
        'result_available', 'risk_attention', 'manual_review',
    ];

    /** @param list<string> $contextualTriggers */
    public function __construct(
        public string $id,
        public string $kind,
        public string $purpose,
        public string $role,
        public ExperienceMode $recommendedMode,
        public int $priority,
        public string $disclosureRule = 'mode_default',
        public array $contextualTriggers = [],
        public ?string $capabilityId = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/D', $id)
            || !in_array($kind, self::KINDS, true)
            || !in_array($role, self::ROLES, true)
            || trim($purpose) === '' || strlen($purpose) > 500
            || $priority < 0 || $priority > 1000
            || !in_array($disclosureRule, self::RULES, true)
            || !array_is_list($contextualTriggers)
            || count($contextualTriggers) !== count(array_unique($contextualTriggers))
            || array_diff($contextualTriggers, self::TRIGGERS) !== []
            || ($disclosureRule === 'on_trigger' && $contextualTriggers === [])
            || ($capabilityId !== null && !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $capabilityId))) {
            throw new InvalidArgumentException('Invalid declarative COS Experience Semantics v1 contract.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $this->id,
            'kind' => $this->kind,
            'purpose' => $this->purpose,
            'role' => $this->role,
            'recommended_mode' => $this->recommendedMode->value,
            'priority' => $this->priority,
            'disclosure_rule' => $this->disclosureRule,
            'contextual_triggers' => $this->contextualTriggers,
            'capability_id' => $this->capabilityId,
        ];
    }
}
