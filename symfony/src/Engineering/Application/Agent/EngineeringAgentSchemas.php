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
                'tasks' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object']],
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
        $gate = ['APPROVED','APPROVED_WITH_CONDITIONS','REJECTED','NEEDS_HUMAN_DECISION'];

        return [
            'type' => 'object',
            'required' => ['status','architecture_decision','implementation_plan','developer_handoff','documentation_changes','conditions','risks','unresolved_questions','required_human_decisions'],
            'properties' => [
                'status' => self::baseStatus($gate),
                'architecture_decision' => [
                    'type' => 'object',
                    'required' => ['affected_domains','primary_owner_domain','bounded_context','current_architecture','proposed_solution','components','interfaces','data_flow','dependencies','database_changes','api_changes','events','identity','permissions','tenant_isolation','security','migration_strategy','backward_compatibility','observability','testing_strategy','risks','alternatives_considered','decision'],
                    'properties' => [
                        'affected_domains' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'primary_owner_domain' => ['type' => 'string'],
                        'bounded_context' => ['type' => ['string','object']],
                        'current_architecture' => ['type' => ['string','object']],
                        'proposed_solution' => ['type' => ['string','object']],
                        'components' => ['type' => 'array'],
                        'interfaces' => ['type' => 'array'],
                        'data_flow' => ['type' => ['array','object','string']],
                        'dependencies' => ['type' => 'array'],
                        'database_changes' => ['type' => ['object','array']],
                        'api_changes' => ['type' => ['object','array']],
                        'events' => ['type' => ['object','array']],
                        'identity' => ['type' => ['object','array','string']],
                        'permissions' => ['type' => ['array','object','string']],
                        'tenant_isolation' => ['type' => ['object','array','string']],
                        'security' => ['type' => ['object','array','string']],
                        'migration_strategy' => ['type' => ['string','object','array','null']],
                        'backward_compatibility' => ['type' => ['object','array','string']],
                        'observability' => ['type' => ['object','array','string']],
                        'testing_strategy' => ['type' => ['object','array','string']],
                        'risks' => ['type' => 'array'],
                        'alternatives_considered' => ['type' => 'array'],
                        'decision' => ['type' => ['string','object']],
                    ],
                    'additionalProperties' => false,
                ],
                'implementation_plan' => [
                    'type' => 'object',
                    'required' => ['steps','files_to_create','files_to_modify','services','controllers','commands','entities','repositories','frontend_components','migrations','tests_required','documentation_updates','completion_conditions'],
                    'properties' => [
                        'steps' => ['type' => 'array'],
                        'files_to_create' => ['type' => 'array'],
                        'files_to_modify' => ['type' => 'array'],
                        'services' => ['type' => 'array'],
                        'controllers' => ['type' => 'array'],
                        'commands' => ['type' => 'array'],
                        'entities' => ['type' => 'array'],
                        'repositories' => ['type' => 'array'],
                        'frontend_components' => ['type' => 'array'],
                        'migrations' => ['type' => 'array'],
                        'tests_required' => ['type' => ['array','object']],
                        'documentation_updates' => ['type' => 'array'],
                        'completion_conditions' => ['type' => 'array'],
                    ],
                    'additionalProperties' => false,
                ],
                'developer_handoff' => [
                    'type' => 'object',
                    'required' => ['mandatory_constraints','forbidden_changes','interfaces_to_respect','tests_required','completion_conditions'],
                    'properties' => [
                        'mandatory_constraints' => ['type' => 'array'],
                        'forbidden_changes' => ['type' => 'array'],
                        'interfaces_to_respect' => ['type' => 'array'],
                        'tests_required' => ['type' => ['array','object']],
                        'completion_conditions' => ['type' => 'array'],
                    ],
                    'additionalProperties' => false,
                ],
                'documentation_changes' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'required' => ['path','operation','content'],
                        'properties' => [
                            'path' => ['type' => 'string', 'minLength' => 1],
                            'operation' => self::baseStatus(['CREATE','UPDATE']),
                            'content' => ['type' => 'string', 'maxLength' => 120000],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'conditions' => ['type' => 'array'],
                'risks' => ['type' => 'array'],
                'unresolved_questions' => ['type' => 'array'],
                'required_human_decisions' => [
                    'type' => 'array',
                    'maxItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['question','reason','options'],
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'question' => ['type' => 'string', 'minLength' => 1],
                            'reason' => ['type' => 'string', 'minLength' => 1],
                            'options' => ['type' => 'array', 'minItems' => 1],
                            'recommended_option' => ['type' => ['string','null']],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function developer(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'status','preflight','scope','repository_revision','changed_files','implementation_summary',
                'database_changes','api_changes','acceptance_criteria_evidence','tests_added','tests_run',
                'validation','architecture_compliance','security','known_limitations','deviations_from_plan',
                'risks','findings','follow_up_required','changes',
            ],
            'properties' => [
                'status' => self::baseStatus([
                    'COMPLETED','COMPLETED_WITH_LIMITATIONS','BLOCKED',
                    'ARCHITECTURE_REVIEW_REQUIRED','SPECIFICATION_REVIEW_REQUIRED','SECURITY_REVIEW_REQUIRED','FAILED',
                ]),
                'preflight' => [
                    'type' => 'object',
                    'required' => ['status','blockers','architecture_conflicts'],
                    'properties' => [
                        'status' => self::baseStatus(['PASS','BLOCKED']),
                        'blockers' => ['type' => 'array'],
                        'architecture_conflicts' => ['type' => 'array'],
                    ],
                    'additionalProperties' => false,
                ],
                'scope' => [
                    'type' => 'object',
                    'required' => ['requested','implemented','not_implemented'],
                    'properties' => [
                        'requested' => ['type' => 'array'],
                        'implemented' => ['type' => 'array'],
                        'not_implemented' => ['type' => 'array'],
                    ],
                    'additionalProperties' => false,
                ],
                'repository_revision' => ['type' => ['string','null']],
                'branch' => ['type' => ['string','null']],
                'pull_request' => ['type' => ['string','integer','null']],
                'changed_files' => ['type' => 'array'],
                'implementation_summary' => ['type' => 'string'],
                'database_changes' => ['type' => ['object','array']],
                'api_changes' => ['type' => ['object','array']],
                'acceptance_criteria_evidence' => ['type' => 'array'],
                'tests_added' => ['type' => 'array'],
                'tests_run' => ['type' => 'array'],
                'validation' => [
                    'type' => 'object',
                    'required' => ['commands_required','passed','failed','skipped'],
                    'properties' => [
                        'commands_required' => ['type' => 'array'],
                        'passed' => ['type' => 'array'],
                        'failed' => ['type' => 'array'],
                        'skipped' => ['type' => 'array'],
                    ],
                    'additionalProperties' => false,
                ],
                'architecture_compliance' => [
                    'type' => 'object',
                    'required' => ['adr_followed','deviations'],
                    'properties' => [
                        'adr_followed' => ['type' => 'boolean'],
                        'deviations' => ['type' => 'array'],
                    ],
                    'additionalProperties' => false,
                ],
                'security' => [
                    'type' => 'object',
                    'required' => ['checks_performed','findings'],
                    'properties' => [
                        'checks_performed' => ['type' => 'array'],
                        'findings' => ['type' => 'array'],
                    ],
                    'additionalProperties' => false,
                ],
                'known_limitations' => ['type' => 'array'],
                'deviations_from_plan' => ['type' => 'array'],
                'risks' => ['type' => 'array'],
                'findings' => ['type' => 'array'],
                'follow_up_required' => ['type' => 'array'],
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
        $reviewArea = [
            'type' => 'object',
            'required' => ['status','findings'],
            'properties' => [
                'status' => self::baseStatus(['PASS','FINDINGS','NOT_APPLICABLE']),
                'findings' => ['type' => 'array'],
            ],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'required' => ['status','reviewed_revision','base_revision','pull_request','preflight','summary','issues','correctness','architecture','security','maintainability','database','api','tests','acceptance_criteria','ci','unresolved_blockers','unresolved_majors','recommendation'],
            'properties' => [
                'status' => self::baseStatus(['APPROVED','REQUEST_CHANGES','ARCHITECTURE_REVIEW_REQUIRED','HUMAN_REVIEW_REQUIRED']),
                'reviewed_revision' => ['type' => 'string', 'minLength' => 1],
                'base_revision' => ['type' => 'string', 'minLength' => 1],
                'pull_request' => ['type' => ['integer','string']],
                'preflight' => [
                    'type' => 'object',
                    'required' => ['status','reviewed_revision','diff_complete','required_artifacts_present','ci_evidence_available','blockers'],
                    'properties' => [
                        'status' => self::baseStatus(['PASS','BLOCKED']),
                        'reviewed_revision' => ['type' => 'string', 'minLength' => 1],
                        'diff_complete' => ['type' => 'boolean'],
                        'required_artifacts_present' => ['type' => 'boolean'],
                        'ci_evidence_available' => ['type' => 'boolean'],
                        'blockers' => ['type' => 'array'],
                    ],
                    'additionalProperties' => false,
                ],
                'summary' => ['type' => 'string'],
                'issues' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id','severity','blocking','file','line','category','problem','evidence','impact','expected_fix'],
                        'properties' => [
                            'id' => ['type' => 'string', 'minLength' => 1],
                            'severity' => self::baseStatus(['BLOCKER','MAJOR','MINOR','SUGGESTION']),
                            'blocking' => ['type' => 'boolean'],
                            'file' => ['type' => ['string','null']],
                            'line' => ['type' => ['integer','string','null']],
                            'category' => self::baseStatus(['CORRECTNESS','ARCHITECTURE','SECURITY','MAINTAINABILITY','DATABASE','API','TESTS','OTHER']),
                            'problem' => ['type' => 'string', 'minLength' => 1],
                            'evidence' => ['type' => ['string','array','object']],
                            'impact' => ['type' => 'string', 'minLength' => 1],
                            'expected_fix' => ['type' => 'string', 'minLength' => 1],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'correctness' => $reviewArea,
                'architecture' => ['type' => 'object', 'required' => ['compliant','findings'], 'properties' => ['compliant' => ['type' => 'boolean'], 'findings' => ['type' => 'array']], 'additionalProperties' => false],
                'security' => $reviewArea,
                'maintainability' => $reviewArea,
                'database' => $reviewArea,
                'api' => $reviewArea,
                'tests' => $reviewArea,
                'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id','result','evidence'], 'properties' => ['id' => ['type' => 'string', 'minLength' => 1], 'result' => self::baseStatus(['PASS','FAIL','NOT_COVERED']), 'evidence' => ['type' => ['string','array','object']]], 'additionalProperties' => false]],
                'ci' => ['type' => 'object', 'required' => ['state','total','passed','failed','pending','checks'], 'properties' => ['state' => ['type' => 'string'], 'total' => ['type' => 'integer'], 'passed' => ['type' => 'integer'], 'failed' => ['type' => 'integer'], 'pending' => ['type' => 'integer'], 'checks' => ['type' => 'array']], 'additionalProperties' => false],
                'unresolved_blockers' => ['type' => 'array'],
                'unresolved_majors' => ['type' => 'array'],
                'human_review' => ['type' => ['object','null']],
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
