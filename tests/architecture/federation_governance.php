<?php
declare(strict_types=1);

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$policy = json_decode((string) file_get_contents(
    $root . '/docs/03-architecture/federation-governance-policy.json'
), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($policy) || ($policy['schema_version'] ?? '') !== '1.0.0') {
    throw new RuntimeException('Federation governance schema invalid.');
}
$ids = [];
$critical = ['FED-INV-02', 'FED-INV-03', 'FED-INV-07', 'FED-INV-11'];
foreach (($policy['rules'] ?? []) as $rule) {
    foreach (['id', 'description', 'scope', 'severity', 'check', 'exception_policy', 'owner', 'version'] as $field) {
        if (!is_string($rule[$field] ?? null) || $rule[$field] === '') {
            throw new RuntimeException('Governance rule missing: ' . $field);
        }
    }
    if (isset($ids[$rule['id']]) || !in_array($rule['severity'], ['error', 'warning', 'info'], true)) {
        throw new RuntimeException('Duplicate or malformed governance rule.');
    }
    $ids[$rule['id']] = true;
}
foreach ($critical as $id) {
    if (!isset($ids[$id])) throw new RuntimeException('Missing critical federation rule: ' . $id);
}
foreach (($policy['waivers'] ?? []) as $waiver) {
    foreach (['rule_id', 'component', 'justification', 'risk', 'owner', 'expires_at', 'remediation', 'approval_reference'] as $field) {
        if (!is_string($waiver[$field] ?? null) || $waiver[$field] === '') {
            throw new RuntimeException('Malformed federation waiver: ' . $field);
        }
    }
    if (!isset($ids[$waiver['rule_id']]) || in_array($waiver['rule_id'], $critical, true)) {
        throw new RuntimeException('Critical/unknown federation rule cannot be waived.');
    }
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $waiver['expires_at']);
    if (!$expiry || $expiry < new DateTimeImmutable('today')) {
        throw new RuntimeException('Expired federation waiver.');
    }
}
$definitions = (new ModuleDiscovery($root . '/app/Domains'))->discover();
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog($definitions));
if ($catalog->declared() === []) {
    throw new RuntimeException('Federation capability catalog has no source-controlled identities.');
}
foreach ($catalog->executables() as $id => $contract) {
    if (!is_file($root . '/' . ($contract->tests[0] ?? ''))) {
        throw new RuntimeException('Executable capability lacks traceable test: ' . $id);
    }
}
echo sprintf("Federation governance baseline passed: %d rules, %d domains, %d identities, %d typed executable contracts.\n",
    count($ids), count($definitions), count($catalog->declared()), count($catalog->executables()));
