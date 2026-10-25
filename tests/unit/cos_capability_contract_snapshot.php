<?php
declare(strict_types=1);

use Kernel\Module\CapabilityContract;
use Kernel\Module\CapabilityContractFingerprint;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$check = static function(bool $valid, string $why):void {
    if (!$valid) throw new RuntimeException($why);
};
$reject = static function(callable $run) use ($check):void {
    try { $run(); } catch (DomainException) { return; }
    $check(false, 'Expected fail-closed pinned contract/schema rejection.');
};

$definition = require $root . '/app/Domains/Property/module.php';
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog([
    ModuleDefinition::fromArray($definition),
]));
$first = $catalog->snapshot('property.asset.create', $root);
$second = $catalog->snapshot('property.asset.create', $root);
$check($first === $second && preg_match('/^[a-f0-9]{64}$/D', $first['contract_digest']) === 1,
    'Canonical capability fingerprint is not deterministic.');
$check($catalog->resolvePinned('property.asset.create', '1.0.0', $first['contract_digest'], $root)->id
    === 'property.asset.create', 'Valid pinned contract did not resolve.');
$reject(static fn() => $catalog->resolvePinned('property.asset.create', '1.0.1', $first['contract_digest'], $root));
$reject(static fn() => $catalog->resolvePinned('property.asset.create', '1.0.0', str_repeat('0',64), $root));
$reject(static fn() => $catalog->snapshot('property.catalog', $root));

$validator = new CapabilityJsonInputValidator();
$create = $catalog->describe('property.asset.create');
$get = $catalog->describe('property.asset.get');
$check($create instanceof CapabilityContract && $get instanceof CapabilityContract,'Typed contracts missing.');
$validator->validateOutput($create, [
    'asset' => ['asset_id'=>'PROP-0123456789ABCDEF0123'],
    'legacy_property_id'=>null,
]);
$validator->validateOutput($get, null); // Native query may legitimately return not-found.
$reject(static fn() => $validator->validateOutput($create, ['reference'=>'missing-asset']));
$reject(static fn() => $validator->validateOutput($create, [['asset'=>['asset_id'=>'PROP-X']]]));

// A schema edit without version bump must change its fingerprint and invalidate
// previously approved Plans. Use isolated temp schema copies, no live manifests.
$tmp = sys_get_temp_dir() . '/cos-capability-snapshot-' . bin2hex(random_bytes(7));
$folder = $tmp . '/resources/contracts/property';
if (!mkdir($folder, 0700, true)) throw new RuntimeException('Temp folder failure.');
try {
    foreach (['asset-create-input.schema.json','asset-create-output.schema.json'] as $name) {
        $source = $root . '/resources/contracts/property/' . $name;
        if (file_put_contents($folder . '/' . $name, file_get_contents($source)) === false) {
            throw new RuntimeException('Temp schema copy failed.');
        }
    }
    $before = CapabilityContractFingerprint::snapshot($create, $tmp);
    $output = $folder . '/asset-create-output.schema.json';
    $schema = json_decode((string)file_get_contents($output), true, 32, JSON_THROW_ON_ERROR);
    $schema['description'] = 'Changed without version bump';
    file_put_contents($output, json_encode($schema, JSON_THROW_ON_ERROR));
    $after = CapabilityContractFingerprint::snapshot($create, $tmp);
    $check($before['contract_digest'] !== $after['contract_digest'],
        'Schema changes did not invalidate a pinned contract.');
    $reject(static fn() => $catalog->resolvePinned(
        'property.asset.create', '1.0.0', $before['contract_digest'], $tmp,
    ));
} finally {
    @unlink($folder . '/asset-create-input.schema.json');
    @unlink($folder . '/asset-create-output.schema.json');
    @rmdir($folder);
    @rmdir($tmp . '/resources/contracts');
    @rmdir($tmp . '/resources');
    @rmdir($tmp);
}

echo "Capability Library fingerprints, pinning and output schemas PASS.\n";
