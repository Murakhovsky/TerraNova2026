<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'app/Platform/FeatureFlag/Model/FeatureFlagKey.php',
    'app/Platform/FeatureFlag/Model/FeatureFlagContext.php',
    'app/Platform/FeatureFlag/Model/FeatureFlagDefinition.php',
    'app/Platform/FeatureFlag/Model/FeatureFlagOverride.php',
    'app/Platform/FeatureFlag/Model/FeatureFlagOverrideScope.php',
    'app/Platform/FeatureFlag/Model/FeatureFlagDecision.php',
    'app/Platform/FeatureFlag/Model/FeatureFlagDecisionReason.php',
    'app/Platform/FeatureFlag/Contract/FeatureFlagRepositoryInterface.php',
    'app/Platform/FeatureFlag/Service/FeatureFlagResolver.php',
    'app/Infrastructure/Platform/Persistence/MySql/FeatureFlag/MysqlFeatureFlagRepository.php',
    'app/migrations/20260921_000065_platform_feature_flags.sql',
    'symfony/src/Web/Experience/Feature/WebFeatureFlags.php',
    'symfony/src/Command/FeatureFlagPlatformSmokeCommand.php',
    'docs/03-architecture/feature-flags.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.20 feature flag artifact is missing: ' . $relative);
    }
}

$resolver = (string) file_get_contents($root . '/app/Platform/FeatureFlag/Service/FeatureFlagResolver.php');
foreach ([
    'FeatureFlagDecisionReason::UnknownFlag',
    'FeatureFlagDecisionReason::MasterDisabled',
    'FeatureFlagDecisionReason::UserOverride',
    'FeatureFlagDecisionReason::OrganizationOverride',
    'FeatureFlagDecisionReason::PercentageRollout',
    "hash('sha256'",
    '% 10000',
    'rolloutPercentage * 100',
] as $marker) {
    if (!str_contains($resolver, $marker)) {
        throw new RuntimeException('Feature flag resolver contract is incomplete: ' . $marker);
    }
}

foreach (['rand(', 'mt_rand(', 'random_int(', 'random_bytes('] as $forbidden) {
    if (str_contains($resolver, $forbidden)) {
        throw new RuntimeException('Feature rollout must be deterministic, not random: ' . $forbidden);
    }
}

$context = (string) file_get_contents($root . '/app/Platform/FeatureFlag/Model/FeatureFlagContext.php');
if (!str_contains($context, "return \$this->organizationId . ':' . (\$this->userId ?? 'organization');")) {
    throw new RuntimeException('Feature rollout subject must not depend on presentation surface.');
}

$repository = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/FeatureFlag/MysqlFeatureFlagRepository.php');
foreach ([
    'FeatureFlagOverrideScope::User',
    'FeatureFlagOverrideScope::Organization',
    'cos_feature_flags',
    'cos_feature_flag_overrides',
    'expires_at IS NULL OR expires_at > :at',
] as $marker) {
    if (!str_contains($repository, $marker)) {
        throw new RuntimeException('Feature flag repository contract is incomplete: ' . $marker);
    }
}

$userPosition = strpos($repository, 'FeatureFlagOverrideScope::User');
$organizationPosition = strpos($repository, 'FeatureFlagOverrideScope::Organization');
if ($userPosition === false || $organizationPosition === false || $userPosition >= $organizationPosition) {
    throw new RuntimeException('User feature flag override must have higher precedence than organization override.');
}

$migration = (string) file_get_contents($root . '/app/migrations/20260921_000065_platform_feature_flags.sql');
foreach ([
    'CREATE TABLE IF NOT EXISTS cos_feature_flags',
    'CREATE TABLE IF NOT EXISTS cos_feature_flag_overrides',
    'rollout_percentage',
    'rollout_salt',
    'starts_at',
    'ends_at',
    "ENUM('ORGANIZATION','USER')",
    'uq_cos_feature_flag_override',
    'FOREIGN KEY (flag_key)',
] as $marker) {
    if (!str_contains($migration, $marker)) {
        throw new RuntimeException('Feature flag migration contract is incomplete: ' . $marker);
    }
}

$ownership = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach (['cos_feature_flags', 'cos_feature_flag_overrides'] as $table) {
    if (!str_contains($ownership, "'{$table}'")) {
        throw new RuntimeException('Feature flag table ownership is missing: ' . $table);
    }
}

$web = (string) file_get_contents($root . '/symfony/src/Web/Experience/Feature/WebFeatureFlags.php');
foreach ([
    'TenantContextProviderInterface',
    '$this->tenants->current()',
    '$tenant?->organizationId()->value()',
    '$tenant?->userId()->value()',
    'FeatureFlagResolver',
] as $marker) {
    if (!str_contains($web, $marker)) {
        throw new RuntimeException('Web feature flag adapter is incomplete: ' . $marker);
    }
}

foreach (['Request ', 'query->', 'request->', 'headers->'] as $forbidden) {
    if (str_contains($web, $forbidden)) {
        throw new RuntimeException('Feature flag tenant/user context must not come from client request data.');
    }
}

$featureRoot = $root . '/app/Platform/FeatureFlag';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($featureRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $contents = (string) file_get_contents($file->getPathname());
    foreach (['App\\Web\\', 'Twig\\', 'Kernel\\Module\\'] as $forbidden) {
        if (str_contains($contents, $forbidden)) {
            throw new RuntimeException('Feature flag Platform layer contains forbidden coupling: ' . $forbidden);
        }
    }
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'Platform\\FeatureFlag\\Contract\\FeatureFlagRepositoryInterface:',
    'Infrastructure\\Platform\\Persistence\\MySql\\FeatureFlag\\MysqlFeatureFlagRepository',
    'Platform\\FeatureFlag\\Service\\FeatureFlagResolver: ~',
    'App\\Web\\Experience\\Feature\\WebFeatureFlags:',
] as $marker) {
    if (!str_contains($services, $marker)) {
        throw new RuntimeException('Feature flag service wiring is incomplete: ' . $marker);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/FeatureFlagPlatformSmokeCommand.php');
if (!str_contains($smoke, "name: 'cos:platform:feature-flags:smoke'")) {
    throw new RuntimeException('Feature flag runtime smoke is missing.');
}

echo "Wave 12.20 Feature Flags passed.\n";
