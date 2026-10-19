<?php
declare(strict_types=1);

use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$manifest = require $root . '/app/Domains/Property/module.php';
$validator = new CapabilityJsonInputValidator();
$contracts = [];
foreach ($manifest['contributions']['capability_contracts'] as $raw) {
    $contract = CapabilityContract::fromArray($raw);
    $contracts[$contract->id] = $contract;
}
$valid = [
    'property.asset.create' => [
        ['type_code'=>'house','city'=>'Львів','area_total'=>123.5],
        ['type_code'=>'apartment','city'=>'Київ','floor'=>3],
    ],
    'property.asset.update' => [
        ['reference'=>'PROP-123','patch'=>['city'=>'Львів','area_total'=>125.3]],
    ],
    'property.asset.search' => [
        ['q'=>'Львів','page'=>1,'per_page'=>10],
    ],
    'property.asset.get' => [
        ['reference'=>'PROP-123'],
    ],
];
foreach ($valid as $id => $cases) {
    foreach ($cases as $case) $validator->validate($contracts[$id], $case);
}
$invalid = [
    'property.asset.create'=>[
        ['city'=>'Львів'],
        ['type_code'=>'house','city'=>'Львів','asset_id'=>'FORGED'],
        ['type_code'=>'house','city'=>'Львів','area_total'=>'120'],
        ['type_code'=>'house','city'=>'Львів','latitude'=>999],
    ],
    'property.asset.update'=>[
        ['reference'=>'PROP-123','patch'=>['asset_id'=>'FORGED']],
        ['reference'=>'PROP-123','patch'=>['area_total'=>'123']],
        ['reference'=>'PROP-123','patch'=>['publication_state'=>'PUBLISHED']],
    ],
    'property.asset.search'=>[
        ['organization_id'=>'another-tenant'],
        ['per_page'=>9999],
        ['search'=>'wrong key'],
    ],
    'property.asset.get'=>[
        ['reference'=>42],
        ['reference'=>'PROP-123','tenant_id'=>'other'],
    ],
];
foreach ($invalid as $id => $cases) {
    foreach ($cases as $case) {
        try { $validator->validate($contracts[$id], $case); }
        catch (DomainException) { continue; }
        throw new RuntimeException('Expected fail-closed validation for '.$id.': '.json_encode($case));
    }
}
echo "Canonical Property capability input schemas PASS.\n";
