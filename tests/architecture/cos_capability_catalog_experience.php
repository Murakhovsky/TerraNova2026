<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string {
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('Missing '.$path);
    return $value;
};
$check=static function(bool $valid,string $reason):void {
    if(!$valid)throw new RuntimeException($reason);
};
$routes=$read('symfony/config/routes.yaml');
$gateway=$read('symfony/src/Application/Capability/CanonicalCapabilityGateway.php');
$controller=$read('symfony/src/Web/Capability/CapabilityLibraryController.php');
$api=$read('symfony/src/Http/Api/V1/Controller/CanonicalCapabilityController.php');
$catalog=$read('symfony/templates/experience/capability/library.html.twig');
$detail=$read('symfony/templates/experience/capability/detail.html.twig');
$check(str_contains($routes,'/api/v1/capabilities/coverage')
    && str_contains($routes,'/api/v1/capabilities/{id}/versions/{version}')
    && str_contains($routes,'/api/v1/capabilities/{id}/readiness'),
    'Canonical contract coverage/version/readiness routes missing.');
$check(str_contains($api,'$this->capabilities->coverage(')
    && str_contains($gateway,'new CapabilityCatalogCoverageAudit($this->catalog)'),
    'CLI/API must use the same Domain-owned definition compiler.');
$check(str_contains($routes,'/workspace/capabilities/{id}/validate')
    && str_contains($controller,'$this->csrf->isValid($request)')
    && str_contains($controller,'$this->capabilities->validateInput($actor,$id,$input)'),
    'Human validator must be CSRF-protected and use canonical preflight.');
$from=strpos($controller,'public function validate(');
$to=strpos($controller,'/** POST is a single explicit human mutation', $from);
$check(is_int($from) && is_int($to) && $to>$from,
    'Cannot identify no-execution contract workspace boundary.');
$fragment=substr($controller,$from,$to-$from);
foreach(['->invoke(', '->dispatch(', '->submit(', '->start(', '->proposePlan(']as $forbidden) {
    $check(!str_contains($fragment,$forbidden),'Capability dry-run may not mutate: '.$forbidden);
}
$check(str_contains($catalog,'data-capability-coverage')
    && str_contains($catalog,'selectedDomain')
    && str_contains($catalog,'selectedKind')
    && str_contains($detail,'data-cos-capability-detail')
    && str_contains($detail,'name="input_json"')
    && str_contains($detail,'Контракт перевірено, але нічого не запущено'),
    'Operator catalog and safe typed preflight detail must be discoverable.');
$check(str_contains($gateway,"'authorized_to_execute'=>false")
    && str_contains($gateway,"'certified'=>false"),
    'Versioned contract and readiness must never issue execution authority.');
echo "Capability Library read-only governance, human UX, version/readiness routes PASS.\n";
