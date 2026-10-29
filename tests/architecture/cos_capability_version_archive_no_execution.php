<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string {
    $v=file_get_contents($root.'/'.$path);
    if(!is_string($v))throw new RuntimeException('Missing '.$path);
    return $v;
};
$check=static function(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
};
$source=$read('app/Kernel/Module/CapabilityVersionArchive.php');
$fingerprint=$read('app/Kernel/Module/CapabilityContractFingerprint.php');
$cli=$read('bin/cos-capability-release-freeze.php');
$gateway=$read('symfony/src/Application/Capability/CanonicalCapabilityGateway.php');
$routes=$read('symfony/config/routes.yaml');
$legacy=$read('app/Platform/Orchestration/Goal/GoalCapabilityPinGuard.php');
$check(str_contains($source,'CapabilityContractFingerprint::verifyFrozen')
    && str_contains($source,'realpath(') && str_contains($source,'1048576')
    && str_contains($source,"'resources/contracts/history/'"),
    'Historical archive must verify bounded locally owned immutable bytes.');
$check(str_contains($fingerprint,'function verifyFrozen(')
    && str_contains($fingerprint,'function freeze(')
    && str_contains($fingerprint,'input_schema_digest')
    && str_contains($fingerprint,'output_schema_digest'),
    'Version snapshot must freeze and verify both schema contents.');
$check(str_contains($cli,"fopen($"."path,'x')") && str_contains($cli,"in_array('--write'")
    && str_contains($cli,"in_array('--check'"),
    'Archiver must be operator-only and write-once without overwrite.');
$check(str_contains($routes,'/api/v1/capabilities/{id}/versions/{version}/snapshots/{digest}')
    && str_contains($gateway,'function archivedContract(')
    && str_contains($gateway,"'execution_authorized'=>false")
    && str_contains($gateway,"'compatible_handler_verified'=>false"),
    'Historical reads must never grant execution or handler compatibility.');
$check(str_contains($legacy,'$this->catalog->resolvePinned(')
    && !str_contains($legacy,'CapabilityVersionArchive'),
    'Goal runtime must still reject expired handlers; no history-based replay fallback.');
echo "Capability Version Archive local freeze, verify, no historical execution PASS.\n";
