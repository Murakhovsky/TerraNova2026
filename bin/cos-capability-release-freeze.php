#!/usr/bin/env php
<?php
declare(strict_types=1);

use Kernel\Module\CapabilityContractFingerprint;
use Kernel\Module\CapabilityVersionArchive;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';

// Explicit operator-only release maintenance. No web route, no implicit
// writes during module discovery, no replacing a previously reviewed archive.
$write=in_array('--write', $argv, true);
$check=in_array('--check', $argv, true);
$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root.'/app/Domains'))->discover()
));
$archive=new CapabilityVersionArchive($root);
$report=['format'=>'cos-capability-freeze-report-v1','write_requested'=>$write,
    'created'=>[], 'verified'=>[], 'missing'=>[], 'conflicts'=>[]];
foreach ($catalog->executables() as $id => $contract) {
    try {
        $frozen=CapabilityContractFingerprint::freeze($contract,$root);
        $pin=$frozen['snapshot'];
        $relative=CapabilityVersionArchive::relativePath($id,$contract->version,$pin['contract_digest']);
        $path=$root.'/'.$relative;
        $data=json_encode($frozen,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT
            |JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        if (!is_file($path) && $write) {
            $parent=dirname($path);
            if (!is_dir($parent) && !mkdir($parent,0775,true) && !is_dir($parent)) {
                throw new RuntimeException('Cannot create protected release archive directory.');
            }
            $handle=fopen($path,'x'); // exclusive create only; NEVER overwrite
            if ($handle === false) throw new RuntimeException('Archive appeared concurrently.');
            try {
                $bytes=fwrite($handle,$data);
                if ($bytes!==strlen($data)) throw new RuntimeException('Short archive write.');
                fflush($handle);
            } finally {
                fclose($handle);
            }
            $report['created'][]=$relative;
        }
        if (!is_file($path)) {
            $report['missing'][]=$relative;
            continue;
        }
        $snapshot=$archive->read($id,$contract->version,$pin['contract_digest']);
        if ($snapshot !== $frozen) {
            // Compare the whole frozen schema and contract, not just the pin.
            throw new RuntimeException('Frozen snapshot bytes differ from current definition.');
        }
        $report['verified'][]=$relative;
    } catch (Throwable $error) {
        $report['conflicts'][]=['capability_id'=>$id,
            'code'=>'FREEZE_CONFLICT', 'reason'=>$error->getMessage()];
    }
}
foreach (['created','verified','missing'] as $key) sort($report[$key], SORT_STRING);
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT
    |JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
if ($report['conflicts'] !== []) exit(2);
if ($check && $report['missing'] !== []) exit(1);
