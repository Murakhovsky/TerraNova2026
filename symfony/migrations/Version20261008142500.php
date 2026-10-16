<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Additive federation persistence. No Domain-owned table changes.
 */
final class Version20261008142500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'COS Federation tenant-scoped goals, plans, executions, evidence and experience preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Federation migration requires MySQL.'
        );

        $this->addSql(<<<'SQL'
CREATE TABLE cos_federation_goals (
    organization_id VARCHAR(190) NOT NULL,
    goal_id VARCHAR(64) NOT NULL,
    owner_id VARCHAR(190) NOT NULL,
    current_spec_version INT UNSIGNED NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'draft',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, goal_id),
    KEY idx_fed_goal_owner (organization_id, owner_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_federation_goal_specs (
    organization_id VARCHAR(190) NOT NULL,
    goal_id VARCHAR(64) NOT NULL,
    spec_version INT UNSIGNED NOT NULL,
    specification JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, goal_id, spec_version),
    CONSTRAINT fk_fed_goal_spec FOREIGN KEY (organization_id, goal_id)
        REFERENCES cos_federation_goals (organization_id, goal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_federation_plans (
    organization_id VARCHAR(190) NOT NULL,
    plan_id VARCHAR(64) NOT NULL,
    goal_id VARCHAR(64) NOT NULL,
    spec_version INT UNSIGNED NOT NULL,
    plan_version INT UNSIGNED NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'proposed',
    plan_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, plan_id),
    UNIQUE KEY uq_fed_plan_version (organization_id, goal_id, plan_version),
    CONSTRAINT fk_fed_plan_goal FOREIGN KEY (organization_id, goal_id)
        REFERENCES cos_federation_goals (organization_id, goal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_federation_runs (
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(64) NOT NULL,
    goal_id VARCHAR(64) NOT NULL,
    plan_id VARCHAR(64) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'pending',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    checkpoint_id VARCHAR(190) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, run_id),
    KEY idx_fed_run_goal (organization_id, goal_id, state),
    CONSTRAINT fk_fed_run_plan FOREIGN KEY (organization_id, plan_id)
        REFERENCES cos_federation_plans (organization_id, plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_federation_steps (
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(64) NOT NULL,
    step_id VARCHAR(64) NOT NULL,
    capability_id VARCHAR(190) NOT NULL,
    capability_version VARCHAR(64) NOT NULL,
    side_effect_level VARCHAR(24) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    idempotency_key VARCHAR(190) NOT NULL,
    result_reference VARCHAR(255) NULL,
    checkpoint_json JSON NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, run_id, step_id),
    UNIQUE KEY uq_fed_step_dedup (organization_id, idempotency_key),
    CONSTRAINT fk_fed_step_run FOREIGN KEY (organization_id, run_id)
        REFERENCES cos_federation_runs (organization_id, run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_federation_evaluations (
    organization_id VARCHAR(190) NOT NULL,
    evaluation_id VARCHAR(64) NOT NULL,
    goal_id VARCHAR(64) NOT NULL,
    spec_version INT UNSIGNED NOT NULL,
    result VARCHAR(32) NOT NULL,
    evaluation_json JSON NOT NULL,
    evaluated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, evaluation_id),
    KEY idx_fed_evaluation_goal (organization_id, goal_id, evaluated_at),
    CONSTRAINT fk_fed_eval_goal FOREIGN KEY (organization_id, goal_id)
        REFERENCES cos_federation_goals (organization_id, goal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_experience_profiles (
    organization_id VARCHAR(190) NOT NULL,
    user_id VARCHAR(190) NOT NULL,
    default_mode VARCHAR(16) NOT NULL DEFAULT 'result',
    profile_version INT UNSIGNED NOT NULL DEFAULT 1,
    settings_json JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_workspace_experience_states (
    organization_id VARCHAR(190) NOT NULL,
    user_id VARCHAR(190) NOT NULL,
    workspace_key VARCHAR(190) NOT NULL,
    entity_key VARCHAR(190) NOT NULL DEFAULT '',
    selected_mode VARCHAR(16) NOT NULL,
    state_version INT UNSIGNED NOT NULL DEFAULT 1,
    state_json JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, user_id, workspace_key, entity_key),
    CONSTRAINT fk_fed_workspace_profile FOREIGN KEY (organization_id, user_id)
        REFERENCES cos_experience_profiles (organization_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        throw new \RuntimeException(
            'Federation state contains durable business evidence: rollback must disable the feature, not delete records.'
        );
    }
}
