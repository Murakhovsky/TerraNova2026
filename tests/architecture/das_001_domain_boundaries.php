<?php
declare(strict_types=1);

/**
 * DAS-001 authoritative source/ownership gate for installable Domains.
 *
 * This is static architectural evidence only: it does not grant invocation,
 * verify execution outcomes, or upgrade D3-D5 readiness.
 */
$root = dirname(__DIR__, 2);
$domainRoot = $root . '/app/Domains';
$errors = [];
$fail = static function (string $message) use (&$errors): void {
    $errors[] = $message;
};
$nonempty = static fn (mixed $value): bool => is_string($value) && trim($value) !== '';
$loadJson = static function (string $file): array {
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Expected JSON object: ' . $file);
    }
    return $data;
};

$modules = [];
$directoryOwners = [];
$installableDirectories = glob($domainRoot . '/*/module.php') ?: [];
sort($installableDirectories);
foreach ($installableDirectories as $file) {
    $manifest = require $file;
    $id = $manifest['id'] ?? null;
    if (!is_array($manifest) || !$nonempty($id)) {
        $fail('Invalid module manifest: ' . $file);
        continue;
    }
    if (isset($modules[$id])) {
        $fail('Duplicate installable Domain ID: ' . $id);
        continue;
    }
    $modules[$id] = $manifest;
    $directoryOwners[basename(dirname($file))] = $id;
}
// Some bounded contexts (e.g. Spatial) publish ports before becoming installable modules.
foreach (glob($domainRoot . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
    $name = basename($directory);
    if (!isset($directoryOwners[$name])) {
        $directoryOwners[$name] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}

/** @return list<string> */
$validateBoundary = static function (string $owner, array $boundary) use ($root, $directoryOwners, $nonempty): array {
    $issues = [];
    $contract = $boundary['contract'] ?? null;
    $role = $boundary['role'] ?? null;
    $counterpart = $boundary['counterpart'] ?? null;
    if (!$nonempty($contract) || !$nonempty($counterpart) || !$nonempty($boundary['purpose'] ?? null)
        || !$nonempty($boundary['kind'] ?? null)) {
        return ['boundary missing contract/counterpart/kind/purpose'];
    }
    if (!in_array($role, ['requires', 'provides'], true)) {
        $issues[] = 'invalid role';
    }
    if ($counterpart === $owner || !in_array($counterpart, $directoryOwners, true)) {
        $issues[] = 'unknown or self counterpart ' . $counterpart;
    }
    $parts = explode('\\', $contract);
    if (count($parts) < 4 || $parts[0] !== 'Domains' || !isset($directoryOwners[$parts[1]])) {
        $issues[] = 'contract is not in a known Domain namespace';
    } else {
        $sourceOwner = $directoryOwners[$parts[1]];
        // Consumer-owned ports and provider-owned public interfaces are both valid.
        if ($sourceOwner !== $owner && $sourceOwner !== $counterpart) {
            $issues[] = 'contract declared from unrelated Domain ' . $sourceOwner;
        }
        $source = $root . '/app/' . str_replace('\\', '/', $contract) . '.php';
        if (!is_file($source)) {
            $issues[] = 'contract source is missing: ' . $contract;
        }
    }
    return $issues;
};

$baseline = $loadJson($root . '/docs/.vitepress/das-001-compliance-baseline.json');
if (($baseline['schema_version'] ?? null) !== 1 || ($baseline['standard'] ?? null) !== 'DAS-001') {
    $fail('Unsupported DAS-001 baseline schema.');
}
$baselineIds = array_keys($baseline['domains'] ?? []);
$moduleIds = array_keys($modules);
sort($baselineIds);
sort($moduleIds);
if ($baselineIds !== $moduleIds) {
    $fail('Installable Domain inventory differs from reviewed baseline. Baseline: '
        . implode(', ', $baselineIds) . '; modules: ' . implode(', ', $moduleIds));
}
$allCapabilities = [];
$boundaryCount = 0;
$typedCount = 0;
foreach ($modules as $id => $module) {
    $dependencies = $module['dependencies'] ?? [];
    if (!is_array($dependencies) || count($dependencies) !== count(array_unique($dependencies))) {
        $fail($id . ': invalid or duplicate dependencies');
        $dependencies = [];
    }
    foreach ($dependencies as $dependency) {
        if ($dependency === $id || !isset($modules[$dependency])) {
            $fail($id . ': dependency is not a distinct installable Domain: ' . (string) $dependency);
        }
    }
    $contributions = $module['contributions'] ?? [];
    $published = $contributions['capabilities'] ?? [];
    $typed = $contributions['capability_contracts'] ?? [];
    if (!is_array($published) || !is_array($typed)) {
        $fail($id . ': capabilities and capability_contracts must be lists');
        continue;
    }
    foreach ($published as $capability) {
        if (!$nonempty($capability) || !str_starts_with($capability, $id . '.')) {
            $fail($id . ': published capability outside owner namespace');
            continue;
        }
        if (isset($allCapabilities[$capability])) {
            $fail('Duplicate public capability across manifests: ' . $capability);
        }
        $allCapabilities[$capability] = $id;
    }
    foreach ($typed as $contract) {
        $typedCount++;
        if (($contract['owner_domain'] ?? null) !== $id || !in_array($contract['id'] ?? null, $published, true)) {
            $fail($id . ': typed Capability is not owned and published: ' . ($contract['id'] ?? '?'));
        }
        $binding = $contract['execution_binding'] ?? '';
        if (!is_string($binding) || !preg_match('/^(?:query|command|action|read):[a-z][a-z0-9_.-]+$/D', $binding)) {
            $fail($id . ': invalid execution binding on ' . ($contract['id'] ?? '?'));
        }
    }
    $seen = [];
    foreach ($contributions['cross_domain_contracts'] ?? [] as $boundary) {
        $boundaryCount++;
        foreach ($validateBoundary($id, $boundary) as $problem) {
            $fail($id . ': ' . $problem . ' (' . ($boundary['contract'] ?? '?') . ')');
        }
        $key = implode('|', [$boundary['contract'] ?? '', $boundary['role'] ?? '', $boundary['counterpart'] ?? '']);
        if (isset($seen[$key])) {
            $fail($id . ': duplicate cross-domain declaration: ' . $key);
        }
        $seen[$key] = true;
    }
}

// Process Registry and Capability Debt are mutually exhaustive: an unbound
// process step must have exactly one tracked gap, not a fictitious capability.
$gaps = [];
$processCount = 0;
foreach (glob($root . '/resources/processes/*.json') ?: [] as $file) {
    $process = $loadJson($file);
    $processCount++;
    $owner = $process['domain'] ?? '';
    if (!isset($modules[$owner])) {
        $fail('Process has no installable owner: ' . ($process['id'] ?? basename($file)));
        continue;
    }
    foreach ($process['steps'] ?? [] as $step) {
        $domain = $step['domain'] ?? '';
        $capability = $step['capability'] ?? null;
        $key = ($process['id'] ?? '') . ':' . ($step['id'] ?? '');
        if (!isset($modules[$domain])) {
            $fail($key . ': step Domain is not installable: ' . $domain);
            continue;
        }
        if ($domain !== $owner) {
            $declared = false;
            foreach ($modules[$owner]['contributions']['cross_domain_contracts'] ?? [] as $boundary) {
                if (($boundary['role'] ?? null) === 'requires' && ($boundary['counterpart'] ?? null) === $domain) {
                    $declared = true;
                    break;
                }
            }
            if (!$declared) {
                $fail($key . ': foreign step has no requires boundary to ' . $domain);
            }
        }
        if (is_string($capability) && $capability !== '') {
            if (($allCapabilities[$capability] ?? null) !== $domain) {
                $fail($key . ': step Capability is not published by ' . $domain . ': ' . $capability);
            }
        } elseif ($capability === null && ($step['capability_gap'] ?? null) === 'missing-domain-capability') {
            if (isset($gaps[$key])) {
                $fail('Duplicate process gap ' . $key);
            }
            $gaps[$key] = $domain;
        } else {
            $fail($key . ': step has neither published Capability nor supported explicit gap');
        }
    }
}
$debt = $loadJson($root . '/docs/.vitepress/capability-debt.json');
$recorded = [];
foreach ($debt['items'] ?? [] as $item) {
    $key = $item['id'] ?? '';
    if (isset($recorded[$key])) {
        $fail('Duplicate debt registry item: ' . $key);
    }
    $recorded[$key] = true;
    if (!isset($gaps[$key]) || $gaps[$key] !== ($item['owner_domain'] ?? null)) {
        $fail('Stale or foreign process debt item: ' . $key);
    }
}
foreach ($gaps as $key => $owner) {
    if (!isset($recorded[$key])) {
        $fail('Unregistered Process Registry capability gap: ' . $key);
    }
}

// Negative fixtures exercise the actual validation logic, not just constants.
if (in_array('--self-test', $argv, true)) {
    $known = [
        'contract' => 'Domains\\Property\\Contract\\PropertyReferencePort',
        'role' => 'requires',
        'counterpart' => 'property',
        'kind' => 'synchronous_port',
        'purpose' => 'Read canonical Property references.',
    ];
    if ($validateBoundary('sales', $known) !== []
        || $validateBoundary('sales', [...$known, 'role' => 'admin']) === []
        || $validateBoundary('sales', [...$known, 'counterpart' => 'ghost']) === []
        || $validateBoundary('sales', [...$known, 'contract' => 'Domains\\Property\\Contract\\GhostPort']) === []) {
        $fail('DAS-001 cross-domain boundary negative fixtures failed');
    }
}
if ($errors !== []) {
    fwrite(STDERR, "DAS-001 Domain boundary gate failed (" . count($errors) . "):\n- "
        . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "DAS-001 Domain boundary gate PASS: " . count($modules)
    . " installable Domains, " . $typedCount . " typed Capabilities, "
    . $boundaryCount . " declared cross-domain contracts, "
    . $processCount . " processes, " . count($gaps)
    . " explicit gaps. No runtime/D3-D5 readiness inferred.\n";
