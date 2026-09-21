<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'app/Platform/Audit/Model/ActivitySource.php',
    'app/Platform/Audit/Model/ActorKind.php',
    'app/Platform/Audit/Model/ActivityHistoryEntry.php',
    'app/Platform/Audit/Contract/ActivityHistoryRepositoryInterface.php',
    'app/Infrastructure/Platform/Persistence/MySql/Audit/MysqlActivityHistoryRepository.php',
    'app/migrations/20260921_000066_platform_audit_history.sql',
    'symfony/src/Command/AuditHistoryPlatformSmokeCommand.php',
    'docs/03-architecture/audit-history.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.21 audit/history artifact is missing: ' . $relative);
    }
}

$source = (string) file_get_contents($root . '/app/Platform/Audit/Model/ActivitySource.php');
foreach (['HUMAN', 'AGENT', 'TOOL', 'WORKFLOW', 'INTEGRATION', 'WORKER', 'SYSTEM'] as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException('Canonical activity source is incomplete: ' . $marker);
    }
}

$actor = (string) file_get_contents($root . '/app/Platform/Audit/Model/Actor.php');
foreach (['ActorKind::HUMAN', 'ActorKind::AGENT', 'ActorKind::SYSTEM'] as $marker) {
    if (!str_contains($actor, $marker)) {
        throw new RuntimeException('Actor human/agent differentiation is incomplete: ' . $marker);
    }
}

$sink = (string) file_get_contents($root . '/app/Infrastructure/Audit/KernelAuditSink.php');
foreach (["'source' => ", "'actor_kind' => "] as $marker) {
    if (!str_contains($sink, $marker)) {
        throw new RuntimeException('Kernel audit bridge loses provenance: ' . $marker);
    }
}

$writer = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Audit/MysqlAuditRepository.php');
foreach (['source_type', "'USER' => 'HUMAN'", "'AGENT' => 'AGENT'"] as $marker) {
    if (!str_contains($writer, $marker)) {
        throw new RuntimeException('Durable audit source persistence is incomplete: ' . $marker);
    }
}

$history = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Audit/MysqlActivityHistoryRepository.php');
foreach ([
    'WHERE organization_id = :organization_id',
    'subject_type = :subject_type AND subject_id = :subject_id',
    'correlation_id = :correlation_id',
    'ORDER BY created_at DESC, id DESC',
    'LIMIT ',
] as $marker) {
    if (!str_contains($history, $marker)) {
        throw new RuntimeException('Tenant-scoped audit history query is incomplete: ' . $marker);
    }
}

if (str_contains($history, 'organization_id = :organization_id') === false) {
    throw new RuntimeException('Audit history must always carry organization scope.');
}

$migration = (string) file_get_contents($root . '/app/migrations/20260921_000066_platform_audit_history.sql');
foreach ([
    "ADD COLUMN source_type ENUM('HUMAN','AGENT','TOOL','WORKFLOW','INTEGRATION','WORKER','SYSTEM')",
    'idx_cos_audit_org_source',
    "WHEN 'USER' THEN 'HUMAN'",
    "WHEN 'AGENT' THEN 'AGENT'",
] as $marker) {
    if (!str_contains($migration, $marker)) {
        throw new RuntimeException('Audit history migration contract is incomplete: ' . $marker);
    }
}

$agentAudit = (string) file_get_contents($root . '/app/Infrastructure/Audit/PlatformAgentAudit.php');
if (!str_contains($agentAudit, 'source: ActivitySource::AGENT')) {
    throw new RuntimeException('Agent audit source is not explicit.');
}

$toolAudit = (string) file_get_contents($root . '/app/Infrastructure/Audit/PlatformToolAudit.php');
if (!str_contains($toolAudit, 'source: ActivitySource::TOOL')) {
    throw new RuntimeException('Tool audit source is not explicit.');
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'Platform\\Audit\\Contract\\ActivityHistoryRepositoryInterface:',
    'Infrastructure\\Platform\\Persistence\\MySql\\Audit\\MysqlActivityHistoryRepository',
] as $marker) {
    if (!str_contains($services, $marker)) {
        throw new RuntimeException('Audit history service wiring is incomplete: ' . $marker);
    }
}

$auditRoot = $root . '/app/Platform/Audit';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($auditRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $contents = (string) file_get_contents($file->getPathname());
    foreach (['App\\Web\\', 'Twig\\'] as $forbidden) {
        if (str_contains($contents, $forbidden)) {
            throw new RuntimeException('Platform Audit contains forbidden presentation coupling: ' . $forbidden);
        }
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/AuditHistoryPlatformSmokeCommand.php');
if (!str_contains($smoke, "name: 'cos:platform:audit-history:smoke'")) {
    throw new RuntimeException('Audit/history runtime smoke is missing.');
}

echo "Wave 12.21 Audit / History passed.\n";
