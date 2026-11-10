<?php
declare(strict_types=1);

use Kernel\Module\CapabilityCatalogCoverageAudit;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleDiscovery;

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$check=static function(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException($why);
};

$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root.'/app/Domains'))->discover()
));
$audit=new CapabilityCatalogCoverageAudit($catalog,$root);
$first=$audit->report();
$second=$audit->report();
$check($first===$second,'Definition audit must be deterministic on unchanged manifests/schemas.');
$counts=$first['counts'];
$check($counts['declared']>=15 && $counts['typed']>=12,
    'Coverage is missing Domain declarations or typed contracts.');
$check($counts['typed']===$counts['schema_valid']+$counts['invalid_contract'],
    'Every typed contract must be accounted for in schema valid/invalid coverage.');
$check($counts['declared']===$counts['typed']+$counts['declarative_only'],
    'Some declarations disappeared from the inventory.');
$check($first['certified']===false && $first['tenant_permissions_checked']===false
    && $first['runtime_bindings_checked']===false
    && $first['worker_fleet_checked']===false,
    'Static declarations cannot certify live execution.');
$ids=array_column($first['capabilities'],'id');
$check($ids===array_values(array_unique($ids)),'Duplicate capability identity surfaced in report.');
$sorted=$ids;
sort($sorted,SORT_STRING);
$check($ids!==[] && $ids===$sorted,'Catalog audit must sort by stable identity.');
$typed=array_values(array_filter($first['capabilities'],static fn(array $r):bool => $r['typed']));
foreach ($typed as $row) {
    if ($row['definition_status']!=='CONTRACT_INVALID') {
        $check(preg_match('/^[a-f0-9]{64}$/D',$row['contract_digest']??'')===1,
            'Schema-valid contract missing SHA-256 fingerprint.');
    }
}
$check(in_array('property.registry',$ids,true),'Declarative Property identity was dropped.');
$property=array_values(array_filter($first['capabilities'],
    static fn(array $r):bool=>$r['id']==='property.registry'));
$check(count($property)===1 && $property[0]['definition_status']==='DECLARATIVE_ONLY'
    && $property[0]['issues'][0]['code']==='MISSING_TYPED_CONTRACT',
    'Declarative-only property cannot be treated as an executable contract.');

// No alternate writer: duplicate owner of an execution binding must
// invalidate BOTH typed contracts, even with different capability IDs.
$propertyManifest=require $root.'/app/Domains/Property/module.php';
$propertyManifest['contributions']['capabilities']=['property.asset.create'];
$propertyManifest['contributions']['capability_contracts']=
    array_values(array_filter($propertyManifest['contributions']['capability_contracts'],
        static fn(array $item):bool => $item['id']==='property.asset.create'));
$shadow=$propertyManifest;
$shadow['id']='shadow';
$shadow['name']='Shadow';
$shadow['contributions']=[
    'capabilities'=>['shadow.asset.create'],
    'capability_contracts'=>[[
        ...$propertyManifest['contributions']['capability_contracts'][0],
        'id'=>'shadow.asset.create',
        'owner_domain'=>'shadow',
    ]],
];
$collisionCatalog=new CanonicalCapabilityCatalog(new ModuleCatalog([
    ModuleDefinition::fromArray($propertyManifest),
    ModuleDefinition::fromArray($shadow),
]));
$collision=(new CapabilityCatalogCoverageAudit($collisionCatalog,$root))->report();
$check($collision['counts']['invalid_contract']===2,
    'Duplicate binding must invalidate both competing owner contracts.');
foreach ($collision['capabilities'] as $row) {
    $check($row['definition_status']==='CONTRACT_INVALID'
        && in_array('DUPLICATE_EXECUTION_BINDING',array_column($row['issues'],'code'),true),
        'Every duplicate binder must carry a machine-readable finding.');
}
echo "Capability Library full-domain coverage, determinism, declarative gaps, collision security PASS.\n";
