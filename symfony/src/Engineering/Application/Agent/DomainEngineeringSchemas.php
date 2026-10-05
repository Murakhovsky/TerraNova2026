<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

final class DomainEngineeringSchemas
{
    /** @return array<string,mixed> */
    public static function manager(): array
    {
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'required' => ['status','domain_specification','capabilities','features','dependencies','domain_acceptance_criteria','risks','open_questions'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['DECOMPOSITION_READY','HUMAN_DECISION_REQUIRED','BLOCKED','FAILED']],
                'domain_specification' => [
                    'type' => 'object',
                    'required' => ['purpose','business_context','scope','out_of_scope','actors','core_entities','external_dependencies','security_requirements','performance_requirements','migration_requirements','future_extensions'],
                    'properties' => [
                        'purpose' => ['type' => 'string'],
                        'business_context' => ['type' => 'string'],
                        'scope' => $stringList,
                        'out_of_scope' => $stringList,
                        'actors' => $stringList,
                        'core_entities' => $stringList,
                        'external_dependencies' => $stringList,
                        'security_requirements' => $stringList,
                        'performance_requirements' => $stringList,
                        'migration_requirements' => $stringList,
                        'future_extensions' => $stringList,
                    ],
                    'additionalProperties' => false,
                ],
                'capabilities' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['key','name','description','kind','required'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'kind' => ['type' => 'string'],
                            'required' => ['type' => 'boolean'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'features' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['key','title','description','capability_key','kind','risk','priority','required','owned_paths','shared_paths','forbidden_paths'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'capability_key' => ['type' => ['string','null']],
                            'kind' => ['type' => 'string', 'enum' => ['FOUNDATION','CORE','INTEGRATION','APPLICATION','UI','INFRASTRUCTURE']],
                            'risk' => ['type' => 'string', 'enum' => ['LOW','MEDIUM','HIGH','CRITICAL']],
                            'priority' => ['type' => 'string', 'enum' => ['P0','P1','P2','P3']],
                            'required' => ['type' => 'boolean'],
                            'owned_paths' => $stringList,
                            'shared_paths' => $stringList,
                            'forbidden_paths' => $stringList,
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'dependencies' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['feature_key','depends_on','type'],
                        'properties' => [
                            'feature_key' => ['type' => 'string'],
                            'depends_on' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['REQUIRES','BLOCKS','EXTENDS','IMPLEMENTS','USES','MIGRATES','INTEGRATES_WITH']],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'domain_acceptance_criteria' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id','description','blocking'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'blocking' => ['type' => 'boolean'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'risks' => $stringList,
                'open_questions' => $stringList,
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function architect(): array
    {
        $detail = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'required' => ['status','domain_architecture','constitution','contracts','domain_acceptance_criteria','implementation_policy','risks','human_decision'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['APPROVED','APPROVED_WITH_CONDITIONS','NEEDS_HUMAN_DECISION','REJECTED']],
                'domain_architecture' => [
                    'type' => 'object',
                    'required' => [
                        'bounded_context','module_structure','namespace_structure','domain_layers','aggregate_boundaries',
                        'database_boundaries','api_boundaries','event_contracts','integration_contracts','dependency_rules',
                        'security_boundaries','permissions_model','audit_model','feature_flags','observability',
                        'failure_model','migration_strategy','testing_strategy'
                    ],
                    'properties' => [
                        'bounded_context' => $detail,
                        'module_structure' => $detail,
                        'namespace_structure' => $detail,
                        'domain_layers' => $detail,
                        'aggregate_boundaries' => $detail,
                        'database_boundaries' => $detail,
                        'api_boundaries' => $detail,
                        'event_contracts' => $detail,
                        'integration_contracts' => $detail,
                        'dependency_rules' => $detail,
                        'security_boundaries' => $detail,
                        'permissions_model' => $detail,
                        'audit_model' => $detail,
                        'feature_flags' => $detail,
                        'observability' => $detail,
                        'failure_model' => $detail,
                        'migration_strategy' => $detail,
                        'testing_strategy' => $detail,
                    ],
                    'additionalProperties' => false,
                ],
                'constitution' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id','rule','rationale'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'rule' => ['type' => 'string'],
                            'rationale' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'contracts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['key','type','version','compatibility','producer_feature_key','consumer_feature_keys','schema'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['DOMAIN_INTERFACE','APPLICATION_INTERFACE','API_CONTRACT','EVENT_CONTRACT','DATABASE_CONTRACT','INTEGRATION_CONTRACT','PERMISSION_CONTRACT']],
                            'version' => ['type' => 'string'],
                            'compatibility' => ['type' => 'string', 'enum' => ['BACKWARD_COMPATIBLE','BREAKING','DEPRECATED']],
                            'producer_feature_key' => ['type' => ['string','null']],
                            'consumer_feature_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'description' => ['type' => 'string'],
                                    'payload' => ['type' => 'array'],
                                    'constraints' => ['type' => 'array'],
                                ],
                                'required' => ['description','payload','constraints'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'domain_acceptance_criteria' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id','description','blocking'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'blocking' => ['type' => 'boolean'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'implementation_policy' => [
                    'type' => 'object',
                    'required' => ['execution_mode','max_parallel_features','integration_strategy','human_gates'],
                    'properties' => [
                        'execution_mode' => ['type' => 'string', 'enum' => ['SEQUENTIAL','PARALLEL','DEPENDENCY_DRIVEN']],
                        'max_parallel_features' => ['type' => 'integer'],
                        'integration_strategy' => ['type' => 'string'],
                        'human_gates' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'additionalProperties' => false,
                ],
                'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'human_decision' => [
                    'type' => ['object','null'],
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['question','reason','options'],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ];
    }
}
