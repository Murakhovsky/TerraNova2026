<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Application\Contract;
interface MethodologyStudioRepositoryInterface
{
 public function packs(string $organizationId):array;
 public function pack(string $organizationId,string $packId):?array;
 public function savePack(string $organizationId,array $pack):void;
 public function versions(string $organizationId,string $packId):array;
 public function version(string $organizationId,string $packId,string $version):?array;
 public function saveVersion(string $organizationId,array $version):void;
 public function activate(string $organizationId,string $packId,string $methodologyVersion,int $runtimeVersion):void;
 public function publishCompiled(string $organizationId,array $pack,int $runtimeVersion,string $methodologyJson,string $contentHash):void;
 public function entities(string $organizationId,string $packId,string $version):array;
 public function saveEntity(string $organizationId,string $packId,string $version,string $type,string $id,array $payload,int $position,bool $enabled):void;
 public function entity(string $organizationId,string $packId,string $version,string $type,string $id):?array;
 public function deleteEntity(string $organizationId,string $packId,string $version,string $type,string $id):void;
 public function scenarios(string $organizationId,string $packId,string $version):array;
 public function saveScenario(string $organizationId,string $packId,string $version,array $scenario):void;
 public function deleteScenario(string $organizationId,string $packId,string $version,string $scenarioId):void;
 public function saveTestResult(string $organizationId,array $result):void;
 public function audit(string $organizationId,array $entry):void;
 public function auditHistory(string $organizationId,string $packId='',string $version='',int $limit=100):array;
 public function diagnosticRuns(string $organizationId,int $limit=100):array;
}
