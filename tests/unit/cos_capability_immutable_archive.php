<?php
declare(strict_types=1);

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityContractFingerprint;
use Kernel\Module\CapabilityVersionArchive;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$manifest=require $root.'/app/Domains/Property/module.php';
$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog([ModuleDefinition::fromArray($manifest)]));
$contract=$catalog->describe('property.asset.create');
if($contract===null)throw new RuntimeException('Property canonical contract missing.');
$check=static function(bool $ok,string $why):void {
    if (!$ok) throw new RuntimeException($why);
};
$reject=static function(callable $fn,string $why)use($check):void {
    try { $fn(); } catch(DomainException){return;}
    $check(false,$why);
};
$frozen=CapabilityContractFingerprint::freeze($contract,$root);
$pin=CapabilityContractFingerprint::verifyFrozen($frozen);
$check($pin===$catalog->snapshot($contract->id,$root),
    'Immutable freeze differs from canonical current-build fingerprint.');
$check($frozen['contract']['id']===$contract->id
    && isset($frozen['input_schema']['type'],$frozen['output_schema']['type']),
    'Release archive must include contract and both frozen JSON schemas.');
$bad=$frozen;
$bad['output_schema']['description']='tampered without a version bump';
$reject(static fn()=>CapabilityContractFingerprint::verifyFrozen($bad),
    'Tampered output schema accepted under original digest.');
$bad=$frozen;
$bad['contract']['title_key']='retconned';
$reject(static fn()=>CapabilityContractFingerprint::verifyFrozen($bad),
    'Tampered Contract metadata accepted under original digest.');
$bad=$frozen;
$bad['snapshot']['contract_digest']=str_repeat('0',64);
$reject(static fn()=>CapabilityContractFingerprint::verifyFrozen($bad),
    'Forged archive digest accepted.');
$reject(static fn()=>CapabilityVersionArchive::relativePath('../escape','1.0.0',$pin['contract_digest']),
    'Archive path traversal accepted.');
$reject(static fn()=>CapabilityVersionArchive::relativePath($contract->id,'latest',$pin['contract_digest']),
    'Unpinned latest version accepted.');

$tmp=sys_get_temp_dir().'/cos-capability-archive-'.bin2hex(random_bytes(6));
$relative=CapabilityVersionArchive::relativePath($contract->id,$contract->version,$pin['contract_digest']);
$file=$tmp.'/'.$relative;
if(!mkdir(dirname($file),0700,true))throw new RuntimeException('Cannot create isolated archive fixture.');
try {
    file_put_contents($file,json_encode($frozen,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $archive=new CapabilityVersionArchive($tmp);
    $read=$archive->read($contract->id,$contract->version,$pin['contract_digest']);
    $check($read===$frozen,'Frozen archive readback not exact.');
    $reject(static fn()=>$archive->read($contract->id,'2.0.0',$pin['contract_digest']),
        'Unknown archived revision silently upgraded.');
    $reject(static fn()=>$archive->read($contract->id,$contract->version,str_repeat('0',64)),
        'Unknown archived digest silently selected.');
    file_put_contents($file,json_encode($bad,JSON_THROW_ON_ERROR));
    $reject(static fn()=>$archive->read($contract->id,$contract->version,$pin['contract_digest']),
        'Changed on-disk archive not rejected.');
} finally {
    @unlink($file);
    @rmdir(dirname($file));
    @rmdir(dirname(dirname($file)));
    @rmdir(dirname(dirname(dirname($file))));
    @rmdir(dirname(dirname(dirname(dirname($file)))));
    @rmdir(dirname(dirname(dirname(dirname(dirname($file))))));
    @rmdir($tmp);
}
echo "Immutable Capability release snapshot: SHA-256, frozen schema, drift, locator and readback PASS.\n";
