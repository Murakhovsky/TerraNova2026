<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering Manager V0.1: persistent engineering workflow, tasks, agent runs, artifacts, findings and human decisions.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_features (
    id CHAR(36) NOT NULL,
    organization_id VARCHAR(64) NOT NULL,
    external_issue_id VARCHAR(64) NULL,
    title VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL,
    status VARCHAR(64) NOT NULL,
    priority VARCHAR(4) NOT NULL,
    complexity VARCHAR(4) NULL,
    business_goal LONGTEXT NULL,
    request_payload JSON NOT NULL,
    specification_summary JSON NULL,
    acceptance_criteria JSON NULL,
    context_map JSON NULL,
    risks JSON NULL,
    assumptions JSON NULL,
    open_questions JSON NULL,
    repository_revision VARCHAR(64) NULL,
    created_by VARCHAR(128) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_feature_org (organization_id),
    KEY idx_cos_eng_feature_status (status, priority),
    KEY idx_cos_eng_feature_issue (external_issue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_tasks (
    id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    external_key VARCHAR(128) NOT NULL,
    type VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT NOT NULL,
    status VARCHAR(32) NOT NULL,
    assigned_role VARCHAR(32) NOT NULL,
    dependencies JSON NOT NULL,
    acceptance_criteria JSON NOT NULL,
    attempt INT NOT NULL,
    max_attempts INT NOT NULL,
    result JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_task_key (feature_id, external_key),
    KEY idx_cos_eng_task_feature_status (feature_id, status),
    CONSTRAINT fk_cos_eng_task_feature FOREIGN KEY (feature_id) REFERENCES cos_engineering_features (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_workflows (
    id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    workflow_type VARCHAR(64) NOT NULL,
    current_state VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    current_task_id CHAR(36) NULL,
    current_agent_run_id CHAR(36) NULL,
    resume_state VARCHAR(64) NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    last_activity_at DATETIME(6) NOT NULL,
    version INT NOT NULL,
    trace_id VARCHAR(64) NOT NULL,
    lock_key VARCHAR(160) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_workflow_feature (feature_id, status),
    KEY idx_cos_eng_workflow_state (current_state),
    CONSTRAINT fk_cos_eng_workflow_feature FOREIGN KEY (feature_id) REFERENCES cos_engineering_features (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_agent_runs (
    id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    task_id CHAR(36) NULL,
    workflow_execution_id CHAR(36) NOT NULL,
    agent_id VARCHAR(128) NOT NULL,
    agent_role VARCHAR(32) NOT NULL,
    parent_run_id CHAR(36) NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    model_provider VARCHAR(80) NOT NULL,
    model VARCHAR(160) NOT NULL,
    input_snapshot JSON NOT NULL,
    output JSON NULL,
    status VARCHAR(32) NOT NULL,
    technical_retry INT NOT NULL,
    logical_attempt INT NOT NULL,
    tokens_input INT NULL,
    tokens_output INT NULL,
    estimated_cost DECIMAL(14,6) NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    error_type VARCHAR(64) NULL,
    error_message LONGTEXT NULL,
    trace_id VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_run_idempotency (idempotency_key),
    KEY idx_cos_eng_run_feature_status (feature_id, status),
    KEY idx_cos_eng_run_workflow (workflow_execution_id),
    CONSTRAINT fk_cos_eng_run_feature FOREIGN KEY (feature_id) REFERENCES cos_engineering_features (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_run_task FOREIGN KEY (task_id) REFERENCES cos_engineering_tasks (id) ON DELETE SET NULL,
    CONSTRAINT fk_cos_eng_run_workflow FOREIGN KEY (workflow_execution_id) REFERENCES cos_engineering_workflows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql('ALTER TABLE cos_engineering_workflows ADD CONSTRAINT fk_cos_eng_workflow_task FOREIGN KEY (current_task_id) REFERENCES cos_engineering_tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cos_engineering_workflows ADD CONSTRAINT fk_cos_eng_workflow_run FOREIGN KEY (current_agent_run_id) REFERENCES cos_engineering_agent_runs (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_human_decision_requests (
    id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    workflow_execution_id CHAR(36) NOT NULL,
    type VARCHAR(64) NOT NULL,
    question LONGTEXT NOT NULL,
    reason LONGTEXT NOT NULL,
    options JSON NOT NULL,
    recommended_option VARCHAR(128) NULL,
    evidence JSON NOT NULL,
    blocking TINYINT(1) NOT NULL,
    status VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    resolved_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_decision_open (feature_id, status, blocking),
    CONSTRAINT fk_cos_eng_decision_feature FOREIGN KEY (feature_id) REFERENCES cos_engineering_features (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_decision_workflow FOREIGN KEY (workflow_execution_id) REFERENCES cos_engineering_workflows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_human_decisions (
    id CHAR(36) NOT NULL,
    request_id CHAR(36) NOT NULL,
    selected_option VARCHAR(128) NOT NULL,
    comment LONGTEXT NULL,
    decided_by VARCHAR(128) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_decision_request (request_id),
    CONSTRAINT fk_cos_eng_human_decision_request FOREIGN KEY (request_id) REFERENCES cos_engineering_human_decision_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_artifacts (
    id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    task_id CHAR(36) NULL,
    agent_run_id CHAR(36) NULL,
    type VARCHAR(48) NOT NULL,
    version INT NOT NULL,
    status VARCHAR(32) NOT NULL,
    content JSON NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    supersedes_artifact_id CHAR(36) NULL,
    created_by_agent VARCHAR(128) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_artifact_version (feature_id, type, version),
    KEY idx_cos_eng_artifact_latest (feature_id, type, status, version),
    CONSTRAINT fk_cos_eng_artifact_feature FOREIGN KEY (feature_id) REFERENCES cos_engineering_features (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_artifact_task FOREIGN KEY (task_id) REFERENCES cos_engineering_tasks (id) ON DELETE SET NULL,
    CONSTRAINT fk_cos_eng_artifact_run FOREIGN KEY (agent_run_id) REFERENCES cos_engineering_agent_runs (id) ON DELETE SET NULL,
    CONSTRAINT fk_cos_eng_artifact_previous FOREIGN KEY (supersedes_artifact_id) REFERENCES cos_engineering_artifacts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_findings (
    id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    task_id CHAR(36) NULL,
    agent_run_id CHAR(36) NULL,
    source_role VARCHAR(32) NOT NULL,
    category VARCHAR(32) NOT NULL,
    severity VARCHAR(16) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT NOT NULL,
    evidence JSON NOT NULL,
    status VARCHAR(32) NOT NULL,
    resolved_by_run_id CHAR(36) NULL,
    created_at DATETIME(6) NOT NULL,
    resolved_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_finding_feature (feature_id, status, severity),
    CONSTRAINT fk_cos_eng_finding_feature FOREIGN KEY (feature_id) REFERENCES cos_engineering_features (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_finding_task FOREIGN KEY (task_id) REFERENCES cos_engineering_tasks (id) ON DELETE SET NULL,
    CONSTRAINT fk_cos_eng_finding_run FOREIGN KEY (agent_run_id) REFERENCES cos_engineering_agent_runs (id) ON DELETE SET NULL,
    CONSTRAINT fk_cos_eng_finding_resolved_run FOREIGN KEY (resolved_by_run_id) REFERENCES cos_engineering_agent_runs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_transitions (
    id CHAR(36) NOT NULL,
    workflow_execution_id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    from_state VARCHAR(64) NOT NULL,
    to_state VARCHAR(64) NOT NULL,
    transition_trigger VARCHAR(64) NOT NULL,
    reason LONGTEXT NOT NULL,
    initiated_by_type VARCHAR(32) NOT NULL,
    initiated_by_id VARCHAR(128) NOT NULL,
    agent_run_id CHAR(36) NULL,
    human_decision_id CHAR(36) NULL,
    metadata JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_transition_workflow_time (workflow_execution_id, created_at),
    CONSTRAINT fk_cos_eng_transition_workflow FOREIGN KEY (workflow_execution_id) REFERENCES cos_engineering_workflows (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_transition_feature FOREIGN KEY (feature_id) REFERENCES cos_engineering_features (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_transition_run FOREIGN KEY (agent_run_id) REFERENCES cos_engineering_agent_runs (id) ON DELETE SET NULL,
    CONSTRAINT fk_cos_eng_transition_decision FOREIGN KEY (human_decision_id) REFERENCES cos_engineering_human_decisions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cos_engineering_workflows DROP FOREIGN KEY fk_cos_eng_workflow_run');
        $this->addSql('ALTER TABLE cos_engineering_workflows DROP FOREIGN KEY fk_cos_eng_workflow_task');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_transitions');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_findings');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_artifacts');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_human_decisions');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_human_decision_requests');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_agent_runs');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_workflows');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_tasks');
        $this->addSql('DROP TABLE IF EXISTS cos_engineering_features');
    }
}
