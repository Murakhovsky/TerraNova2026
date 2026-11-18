<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Marketing\Infrastructure\Meta\MetaAdsReadClient;
use Domains\Marketing\Infrastructure\Meta\MetaReadTransportInterface;
use Kernel\Module\ModuleDefinition;

$root=dirname(__DIR__,2);
$definition=ModuleDefinition::fromArray(require $root.'/app/Domains/Marketing/module.php');
$assert=static function(bool $ok,string $message):void {
 if(!$ok)throw new RuntimeException($message);
};
$assert(!$definition->manifest->enabledByDefault,'Marketing must be disabled until tenant acceptance.');
$assert($definition->manifest->id==='marketing','Marketing must own a unique bounded context.');
$assert(count($definition->contributions->capabilityContracts)===3,'Only implemented canonical bindings may be advertised.');
foreach($definition->contributions->capabilityContracts as $contract) {
 $assert(is_file($root.'/'.$contract->inputSchema)&&is_file($root.'/'.$contract->outputSchema),'Missing JSON schemas.');
 $assert(!str_contains($contract->id,'activate')&&!str_contains($contract->id,'budget.update'),'Paid writes must fail closed.');
}
$rootDomain=$root.'/app/Domains/Marketing';
$source=file_get_contents($rootDomain.'/Application/Service/MetaReadOnlyConnectionService.php');
$assert(!str_contains($source,'CURLOPT_POST')&&!str_contains($source,'setDeliveryStatus'),'Meta must be read-only.');
$sql=file_get_contents($root.'/app/migrations/20261118_000156_marketing_core_paid_read.sql');
foreach(['mkt_campaigns','mkt_paid_accounts','mkt_paid_objects','mkt_paid_insight_snapshots','mkt_operation_receipts','organization_id'] as $word) {
 $assert(str_contains($sql,$word),'Marketing persistence invariant missing: '.$word);
}
$http=new class implements MetaReadTransportInterface {
 public array $calls=[];
 public function get(string $url,string $token):array {
  $this->calls[]=[$url,$token];
  return ['data'=>[['id'=>'123','name'=>'Campaign']],'paging'=>[]];
 }
};
$client=new MetaAdsReadClient($http);
$batch=$client->campaigns('123','secret-test');
$assert(count($batch['items'])===1&&$batch['next_cursor']===null,'Meta pagination baseline must be deterministic.');
$assert(str_contains($http->calls[0][0],'/act_123/campaigns?'),'Meta request must be scoped to a declared account.');
$assert(!str_contains($http->calls[0][0],'secret-test'),'Meta token may never enter URLs.');
foreach([fn()=>$client->campaigns('https://evil.test','secret'),fn()=>$client->insights('123','secret','all','2026-11-01','2026-11-07')] as $invalid) {
 try {$invalid();throw new RuntimeException('Invalid Meta input accepted.');}
 catch(InvalidArgumentException){}
}
echo "marketing_domain_boundary OK\n";
