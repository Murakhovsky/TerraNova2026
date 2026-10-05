<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Domain\Agent\AgentRole;
use InvalidArgumentException;

final class EngineeringDomainAgentSchemas
{
    /** @return array<string,mixed> */
    public static function forRole(AgentRole $role): array
    {
        return match ($role) {
            AgentRole::ENGINEERING_MANAGER => self::manager(),
            AgentRole::PRINCIPAL_ARCHITECT => self::architect(),
            AgentRole::QA => self::qa(),
            default => throw new InvalidArgumentException('Agent role does not support Domain Development mode: '.$role->value),
        };
    }

    /** @return array<string,mixed> */
    private static function manager(): array
    {
        return [
            'type' => 'object',
            'required' => ['status','domain_specification','domain_acceptance_criteria','capabilities','risks','open_questions'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['SPECIFICATION_READY','HUMAN_DECISION_REQUIRED','BLOCKED','FAILED']],
                'domain_specification' => [
                    'type' => 'object',
                    'required' => [
                        'key','name','purpose','business_context','scope','actors','core_entities','value_objects','aggregates',
                        'domain_services','repositories','external_dependencies','integration_boundaries','events','commands','queries',
                        'permissions','audit_requirements','security_requirements','data_requirements','performance_requirements',
                        'availability_requirements','migration_requirements','compatibility_requirements','observability_requirements',
                        'known_constraints','future_extensions',
                    ],
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'purpose' => ['type' => 'string'],
                        'business_context' => ['type' => ['string','object','array']],
                        'scope' => ['type' => 'object'],
                        'actors' => ['type' => 'array'],
                        'core_entities' => ['type' => 'array'],
                        'value_objects' => ['type' => 'array'],
                        'aggregates' => ['type' => 'array'],
                        'domain_services' => ['type' => 'array'],
                        'repositories' => ['type' => 'array'],
                        'external_dependencies' => ['type' => 'array'],
                        'integration_boundaries' => ['type' => 'array'],
                        'events' => ['type' => 'array'],
                        'commands' => ['type' => 'array'],
                        'queries' => ['type' => 'array'],
                        'permissions' => ['type' => ['array','object']],
                        'audit_requirements' => ['type' => ['array','object']],
                        'security_requirements' => ['type' => ['array','object']],
                        'data_requirements' => ['type' => ['array','object']],
                        'performance_requirements' => ['type' => ['array','object']],
                        'availability_requirements' => ['type' => ['array','object']],
                        'migration_requirements' => ['type' => ['array','object']],
                        'compatibility_requirements' => ['type' => ['array','object']],
                        'observability_requirements' => ['type' => ['array','object']],
                        'known_constraints' => ['type' => 'array'],
                        'future_extensions' => ['type' => 'array'],
                    ],
                    'additionalProperties' => true,
                ],
                'domain_acceptance_criteria' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object']],
                'capabilities' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['key','name','description','kind','required','depends_on'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => ['FOUNDATION','CORE','INTEGRATION','APPLICATION','UI','INFRASTRUCTURE']],
                            'required' => ['type' => 'boolean'],
                            'depends_on' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'risks' => ['type' => 'array'],
                'open_questions' => ['type' => 'array'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function qa(): array
    {
        return [
            'type' => 'object',
            'required' => ['status','domain_qa_plan','blockers','human_tests_required'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['PLAN_READY','PASS','FAIL','BLOCKED','HUMAN_TEST_REQUIRED']],
                'domain_qa_plan' => [
                    'type' => 'object',
                    'required' => [
                        'domain_acceptance_criteria','cross_feature_workflows','cross_domain_workflows','contract_cases','migration_cases',
                        'permissions','tenant_isolation','security','performance','resilience','regression','smoke','release_blocking_checks',
                    ],
                    'properties' => [
                        'domain_acceptance_criteria' => ['type' => 'array'],
                        'cross_feature_workflows' => ['type' => 'array'],
                        'cross_domain_workflows' => ['type' => 'array'],
                        'contract_cases' => ['type' => 'array'],
                        'migration_cases' => ['type' => 'array'],
                        'permissions' => ['type' => 'array'],
                        'tenant_isolation' => ['type' => 'array'],
                        'security' => ['type' => 'array'],
                        'performance' => ['type' => 'array'],
                        'resilience' => ['type' => 'array'],
                        'regression' => ['type' => 'array'],
                        'smoke' => ['type' => 'array'],
                        'release_blocking_checks' => ['type' => 'array'],
                    ],
                    'additionalProperties' => true,
                ],
                'blockers' => ['type' => 'array'],
                'human_tests_required' => ['type' => 'array'],
                'evidence' => ['type' => 'array'],
                'defects' => ['type' => 'array'],
            ],
            'additionalProperties' => true,
        ];
    }

    /** @return array<string,mixed> */
    private static function architect(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'status','domain_architecture','architecture_constitution','capabilities','features','dependencies','contracts','events',
                'parallelization_groups','critical_path','migration_plan','integration_strategy','release_strategy','required_human_decisions',
            ],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['APPROVED','APPROVED_WITH_CONDITIONS','REJECTED','NEEDS_HUMAN_DECISION']],
                'domain_architecture' => [
                    'type' => 'object',
                    'required' => [
                        'bounded_context','module_structure','namespace_structure','domain_layers','aggregate_boundaries','database_boundaries',
                        'api_boundaries','event_contracts','integration_contracts','dependency_rules','security_boundaries','permissions_model',
                        'audit_model','feature_flags','observability','failure_model','migration_strategy','testing_strategy',
                    ],
                    'properties' => [
                        'bounded_context' => ['type' => ['string','object']],
                        'module_structure' => ['type' => ['array','object']],
                        'namespace_structure' => ['type' => ['array','object']],
                        'domain_layers' => ['type' => ['array','object']],
                        'aggregate_boundaries' => ['type' => ['array','object']],
                        'database_boundaries' => ['type' => ['array','object']],
                        'api_boundaries' => ['type' => ['array','object']],
                        'event_contracts' => ['type' => ['array','object']],
                        'integration_contracts' => ['type' => ['array','object']],
                        'dependency_rules' => ['type' => 'array'],
                        'security_boundaries' => ['type' => ['array','object']],
                        'permissions_model' => ['type' => ['array','object']],
                        'audit_model' => ['type' => ['array','object']],
                        'feature_flags' => ['type' => ['array','object']],
                        'observability' => ['type' => ['array','object']],
                        'failure_model' => ['type' => ['array','object']],
                        'migration_strategy' => ['type' => ['array','object','string']],
                        'testing_strategy' => ['type' => ['array','object']],
                    ],
                    'additionalProperties' => true,
                ],
                'architecture_constitution' => [
                    'type' => 'object',
                    'required' => ['version','rules'],
                    'properties' => [
                        'version' => ['type' => 'string'],
                        'rules' => ['type' => 'array', 'minItems' => 1],
                    ],
                    'additionalProperties' => false,
                ],
                'capabilities' => ['type' => 'array', 'minItems' => 1],
                'features' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => [
                            'key','capability_key','title','description','kind','priority','risk','required','acceptance_criteria',
                            'owned_paths','shared_paths','forbidden_paths','contracts_consumed','contracts_produced',
                        ],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'capability_key' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => ['FOUNDATION','CORE','INTEGRATION','APPLICATION','UI','INFRASTRUCTURE']],
                            'priority' => ['type' => 'string', 'enum' => ['P0','P1','P2','P3']],
                            'risk' => ['type' => 'string', 'enum' => ['LOW','MEDIUM','HIGH','CRITICAL']],
                            'required' => ['type' => 'boolean'],
                            'acceptance_criteria' => ['type' => 'array', 'minItems' => 1],
                            'owned_paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'shared_paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'forbidden_paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'contracts_consumed' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'contracts_produced' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
                'dependencies' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['feature_key','depends_on_key','type'],
                        'properties' => [
                            'feature_key' => ['type' => 'string'],
                            'depends_on_key' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['REQUIRES','BLOCKS','EXTENDS','IMPLEMENTS','USES','MIGRATES','INTEGRATES_WITH']],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'contracts' => ['type' => 'array'],
                'events' => ['type' => 'array'],
                'parallelization_groups' => ['type' => 'array'],
                'critical_path' => ['type' => 'array'],
                'migration_plan' => ['type' => ['object','array']],
                'integration_strategy' => ['type' => ['object','array','string']],
                'release_strategy' => ['type' => ['object','array','string']],
                'required_human_decisions' => ['type' => 'array'],
                'conditions' => ['type' => 'array'],
            ],
            'additionalProperties' => false,
        ];
    }
}
