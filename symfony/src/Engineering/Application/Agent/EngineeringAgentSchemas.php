<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;

final class EngineeringAgentSchemas
{
    /** @return array<string,mixed> */
    public static function forRole(AgentRole $role): array
    {
        return match ($role) {
            AgentRole::ENGINEERING_MANAGER => self::manager(),
            AgentRole::PRINCIPAL_ARCHITECT => self::architect(),
            AgentRole::DEVELOPER => self::developer(),
            AgentRole::REVIEWER => self::reviewer(),
            AgentRole::QA => self::qa(),
        };
    }

    private static function baseStatus(array $values): array
    {
        return ['type' => 'string', 'enum' => $values];
    }

    /** @return array<string,mixed> */
    private static function manager(): array
    {
        return [
            'type' => 'object',
            'required' => ['status','feature','context_map','tasks','risks','assumptions','open_questions','decision'],
            'properties' => [
                'status' => self::baseStatus(['SPECIFICATION_READY','HUMAN_DECISION_REQUIRED','BLOCKED','FAILED']),
                'feature' => [
                    'type' => 'object',
                    'required' => ['title','type','business_goal','user_problem','current_behavior','expected_behavior','scope','out_of_scope','affected_areas','user_roles','functional_requirements','non_functional_requirements','acceptance_criteria','dependencies','constraints','risks','assumptions','open_questions','priority','complexity'],
                    'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1],
                        'type' => self::baseStatus(['FEATURE','BUG','REFACTOR','MIGRATION','MAINTENANCE']),
                        'business_goal' => ['type' => 'string', 'minLength' => 1],
                        'user_problem' => ['type' => ['string','null']],
                        'current_behavior' => ['type' => ['string','null']],
                        'expected_behavior' => ['type' => 'string', 'minLength' => 1],
                        'scope' => ['type' => 'array', 'minItems' => 1],
                        'out_of_scope' => ['type' => 'array'],
                        'affected_areas' => ['type' => 'array'],
                        'user_roles' => ['type' => 'array'],
                        'functional_requirements' => ['type' => 'array', 'minItems' => 1],
                        'non_functional_requirements' => ['type' => 'array'],
                        'acceptance_criteria' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object']],
                        'dependencies' => ['type' => 'array'],
                        'constraints' => ['type' => 'array'],
                        'risks' => ['type' => 'array'],
                        'assumptions' => ['type' => 'array'],
                        'open_questions' => ['type' => 'array'],
                        'priority' => self::baseStatus(['P0','P1','P2','P3']),
                        'complexity' => self::baseStatus(['XS','S','M','L','XL']),
                    ],
                    'additionalProperties' => true,
                ],
                'context_map' => ['type' => 'object'],
                'tasks' => ['type' => 'array', 'items' => ['type' => 'object']],
                'risks' => ['type' => 'array', 'items' => ['type' => 'object']],
                'assumptions' => ['type' => 'array', 'items' => ['type' => ['string','object']]],
                'open_questions' => ['type' => 'array', 'items' => ['type' => 'object']],
                'decision' => [
                    'type' => 'object',
                    'required' => ['type','reason'],
                    'properties' => [
                        'type' => self::baseStatus(['RUN_AGENT','REQUEST_HUMAN_DECISION','RETRY','BLOCK','READY_FOR_HUMAN_APPROVAL','STOP']),
                        'agent' => ['type' => ['string','null']],
                        'reason' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function architect(): array
    {
        return [
            'type' => 'object',
            'required' => ['status','decision_summary','affected_components','architecture_changes','data_changes','api_changes','security_implications','implementation_plan','conditions','risks','open_questions','required_human_decisions'],
            'properties' => [
                'status' => self::baseStatus(['APPROVED','APPROVED_WITH_CONDITIONS','NEEDS_PRODUCT_DECISION','BLOCKED','REJECTED']),
                'decision_summary' => ['type' => 'string'],
                'affected_components' => ['type' => 'array'],
                'architecture_changes' => ['type' => 'array'],
                'data_changes' => ['type' => 'array'],
                'api_changes' => ['type' => 'array'],
                'security_implications' => ['type' => 'array'],
                'migration_strategy' => ['type' => ['string','object','null']],
                'implementation_plan' => ['type' => 'array'],
                'conditions' => ['type' => 'array'],
                'risks' => ['type' => 'array'],
                'open_questions' => ['type' => 'array'],
                'required_human_decisions' => ['type' => 'array'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function developer(): array
    {
        return [
            'type' => 'object',
            'required' => ['status','repository_revision','changed_files','implementation_summary','acceptance_criteria_evidence','tests_added','tests_run','known_limitations','findings','changes'],
            'properties' => [
                'status' => self::baseStatus(['COMPLETED','FAILED','BLOCKED']),
                'repository_revision' => ['type' => ['string','null']],
                'branch' => ['type' => ['string','null']],
                'pull_request' => ['type' => ['string','integer','null']],
                'changed_files' => ['type' => 'array'],
                'implementation_summary' => ['type' => 'string'],
                'acceptance_criteria_evidence' => ['type' => 'array'],
                'tests_added' => ['type' => 'array'],
                'tests_run' => ['type' => 'array'],
                'known_limitations' => ['type' => 'array'],
                'findings' => ['type' => 'array'],
                'changes' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'required' => ['path','operation'],
                        'properties' => [
                            'path' => ['type' => 'string', 'minLength' => 1],
                            'operation' => self::baseStatus(['CREATE','UPDATE','DELETE']),
                            'content' => ['type' => ['string','null'], 'maxLength' => 250000],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'commit_message' => ['type' => ['string','null']],
                'pull_request_title' => ['type' => ['string','null']],
                'pull_request_body' => ['type' => ['string','null']],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function reviewer(): array
    {
        return [
            'type' => 'object',
            'required' => ['status','reviewed_revision','findings','acceptance_criteria','architecture_compliance','security_notes','recommendation'],
            'properties' => [
                'status' => self::baseStatus(['APPROVED','CHANGES_REQUESTED','BLOCKED']),
                'reviewed_revision' => ['type' => 'string'],
                'findings' => ['type' => 'array', 'items' => ['type' => 'object']],
                'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'object']],
                'architecture_compliance' => ['type' => ['string','object','boolean']],
                'security_notes' => ['type' => 'array'],
                'recommendation' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function qa(): array
    {
        return [
            'type' => 'object',
            'required' => ['status','tested_revision','test_plan','acceptance_criteria','tests_total','tests_passed','tests_failed','defects','regressions','known_limitations'],
            'properties' => [
                'status' => self::baseStatus(['PASS','FAIL','BLOCKED']),
                'tested_revision' => ['type' => 'string'],
                'test_plan_version' => ['type' => ['string','integer','null']],
                'test_plan' => ['type' => 'array', 'items' => ['type' => 'object']],
                'acceptance_criteria' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id','result','evidence'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'result' => self::baseStatus(['PASS','FAIL','BLOCKED']),
                            'evidence' => ['type' => ['string','array','object']],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
                'tests_total' => ['type' => 'integer', 'minimum' => 0],
                'tests_passed' => ['type' => 'integer', 'minimum' => 0],
                'tests_failed' => ['type' => 'integer', 'minimum' => 0],
                'defects' => ['type' => 'array'],
                'regressions' => ['type' => 'array'],
                'known_limitations' => ['type' => 'array'],
            ],
            'additionalProperties' => false,
        ];
    }
}
