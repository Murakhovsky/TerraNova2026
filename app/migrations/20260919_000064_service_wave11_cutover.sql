CREATE TABLE IF NOT EXISTS tn_service_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    case_id VARCHAR(80) NOT NULL,
    subject VARCHAR(220) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_case (organization_id,case_id),
    KEY ix_service_case_status (organization_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_service_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    request_id VARCHAR(80) NOT NULL,
    case_id VARCHAR(80) NOT NULL,
    summary VARCHAR(500) NOT NULL,
    requester_ref VARCHAR(191) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_request (organization_id,request_id),
    KEY ix_service_request_case (organization_id,case_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_service_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    ticket_id VARCHAR(80) NOT NULL,
    request_id VARCHAR(80) NOT NULL,
    reference VARCHAR(80) NOT NULL,
    subject VARCHAR(220) NOT NULL,
    priority VARCHAR(24) NOT NULL DEFAULT 'normal',
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    assignee_id VARCHAR(191) NULL,
    escalation_level INT UNSIGNED NOT NULL DEFAULT 0,
    current_assignment_id VARCHAR(80) NULL,
    current_sla_id VARCHAR(80) NULL,
    current_resolution_id VARCHAR(80) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    resolved_at DATETIME(6) NULL,
    closed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_ticket (organization_id,ticket_id),
    UNIQUE KEY uq_service_ticket_reference (organization_id,reference),
    KEY ix_service_ticket_request (organization_id,request_id,status),
    KEY ix_service_ticket_assignee (organization_id,assignee_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_service_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    assignment_id VARCHAR(80) NOT NULL,
    ticket_id VARCHAR(80) NOT NULL,
    assignee_id VARCHAR(191) NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ended_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_assignment (organization_id,assignment_id),
    KEY ix_service_assignment_ticket (organization_id,ticket_id,active,assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_service_slas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    sla_id VARCHAR(80) NOT NULL,
    ticket_id VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    response_minutes INT UNSIGNED NOT NULL,
    resolution_minutes INT UNSIGNED NOT NULL,
    response_due_at DATETIME(6) NOT NULL,
    resolution_due_at DATETIME(6) NOT NULL,
    set_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_sla (organization_id,sla_id),
    KEY ix_service_sla_ticket (organization_id,ticket_id,created_at),
    KEY ix_service_sla_due (organization_id,resolution_due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_service_escalations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    escalation_id VARCHAR(80) NOT NULL,
    ticket_id VARCHAR(80) NOT NULL,
    level INT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    escalated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_escalation (organization_id,escalation_id),
    UNIQUE KEY uq_service_escalation_level (organization_id,ticket_id,level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_service_resolutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    resolution_id VARCHAR(80) NOT NULL,
    ticket_id VARCHAR(80) NOT NULL,
    summary VARCHAR(1000) NOT NULL,
    resolved_by BIGINT UNSIGNED NOT NULL,
    resolved_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_resolution (organization_id,resolution_id),
    UNIQUE KEY uq_service_resolution_ticket (organization_id,ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_service_operation_receipts (
    organization_id VARCHAR(64) NOT NULL,
    operation_type VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,operation_type,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.2.0',
    schema_version='0.2.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='service'
  AND status='INSTALLED'
  AND installed_version='0.1.0'
  AND schema_version='0.1.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260919_000064_service_wave11_cutover');
