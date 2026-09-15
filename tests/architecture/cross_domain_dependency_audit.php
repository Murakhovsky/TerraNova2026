<?php
declare(strict_types=1);

use Kernel\Module\ModuleDefinition;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$declared = [];
foreach (glob($root . '/app/Domains/*/module.php') ?: [] as $moduleFile) {
    $raw = require $moduleFile;
    if (!is_array($raw)) {
        throw new RuntimeException('Invalid module definition: ' . $moduleFile);
    }
    $definition = ModuleDefinition::fromArray($raw, $moduleFile);
    $domain = $definition->manifest->id;
    foreach ($definition->contributions->crossDomainContracts as $contract) {
        $consumer = $contract->consumerDomain($domain);
        $provider = $contract->providerDomain($domain);
        if (isset($declared[$contract->contract])) {
            throw new RuntimeException('Duplicate canonical cross-domain contract declaration: ' . $contract->contract);
        }
        $declared[$contract->contract] = [
            'consumer' => $consumer,
            'provider' => $provider,
            'kind' => $contract->kind,
            'module_file' => $moduleFile,
        ];

        $contractFile = $root . '/app/' . str_replace('\\', '/', $contract->contract) . '.php';
        if (!is_file($contractFile)) {
            throw new RuntimeException('Declared cross-domain contract class does not exist: ' . $contract->contract);
        }
    }
}

if ($declared === []) {
    throw new RuntimeException('No canonical cross-domain contracts are declared.');
}

$legacyAllowlist = [
    'app/Domains/Sales/Infrastructure/Persistence/Phalcon/Telegram/Request/Shows.php' => [
        'Domains\\Property\\Infrastructure\\Persistence\\Phalcon\\Telegram\\Estate\\Objects',
        'Domains\\Identity\\Infrastructure\\Persistence\\Phalcon\\Telegram\\Person\\AppUsers',
    ],
    'app/Domains/Sales/Infrastructure/Persistence/Phalcon/Telegram/Request/Requests.php' => [
        'Domains\\Identity\\Infrastructure\\Persistence\\Phalcon\\Telegram\\Preference\\ListItems',
        'Domains\\Identity\\Infrastructure\\Persistence\\Phalcon\\Telegram\\Preference\\Lists',
    ],
    'app/Domains/Property/Infrastructure/Persistence/Phalcon/Telegram/Estate/Objects.php' => [
        'Domains\\Identity\\Infrastructure\\Persistence\\Phalcon\\Telegram\\Preference\\ListItems',
    ],
    'app/Domains/Property/Infrastructure/Persistence/Phalcon/Telegram/ReObjects.php' => [
        'Domains\\Identity\\Infrastructure\\Persistence\\Phalcon\\Telegram\\Person\\AppUsers',
        'Domains\\Identity\\Infrastructure\\Persistence\\Phalcon\\Telegram\\Preference\\Lists',
    ],
];

$contractEvidence = [];
$legacyEvidence = [];
$violations = [];
$domainDirectories = glob($root . '/app/Domains/*', GLOB_ONLYDIR) ?: [];
foreach ($domainDirectories as $domainDirectory) {
    $sourceDomain = basename($domainDirectory);
    $sourceId = strtolower($sourceDomain);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainDirectory));

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $absolute = $file->getPathname();
        $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
        $source = (string) file_get_contents($absolute);
        if (!preg_match_all('/Domains\\\\([A-Z][A-Za-z0-9]*)\\\\[A-Za-z0-9_\\\\]+/', $source, $matches)) {
            continue;
        }

        foreach (array_unique($matches[0]) as $fqcn) {
            $fqcn = rtrim($fqcn, '\\');
            if (!preg_match('/^Domains\\\\([A-Z][A-Za-z0-9]*)\\\\/', $fqcn, $targetMatch)) {
                continue;
            }
            $targetId = strtolower($targetMatch[1]);
            if ($targetId === $sourceId) {
                continue;
            }

            if (isset($declared[$fqcn])) {
                $relation = $declared[$fqcn];
                if (!in_array($sourceId, [$relation['consumer'], $relation['provider']], true)) {
                    $violations[] = sprintf('%s references %s outside declared consumer/provider domains.', $relative, $fqcn);
                    continue;
                }
                if (basename($relative) !== 'module.php') {
                    $contractEvidence[$fqcn][$sourceId] = true;
                }
                continue;
            }

            if (in_array($fqcn, $legacyAllowlist[$relative] ?? [], true)) {
                $legacyEvidence[$relative . "\0" . $fqcn] = true;
                continue;
            }

            $violations[] = sprintf('%s has undeclared cross-domain dependency on %s.', $relative, $fqcn);
        }
    }
}

foreach ($declared as $fqcn => $relation) {
    if (($contractEvidence[$fqcn] ?? []) === []) {
        $violations[] = sprintf('Declared contract %s has no code evidence outside module metadata.', $fqcn);
    }
}

foreach ($legacyAllowlist as $relative => $classes) {
    foreach ($classes as $fqcn) {
        if (!isset($legacyEvidence[$relative . "\0" . $fqcn])) {
            $violations[] = sprintf('Legacy allowlist entry is stale and should be removed: %s -> %s.', $relative, $fqcn);
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException("Cross-domain dependency audit failed:\n- " . implode("\n- ", $violations));
}

echo sprintf(
    "Cross-domain dependency audit passed: %d canonical contracts, %d explicit legacy debts.\n",
    count($declared),
    count($legacyEvidence),
);
