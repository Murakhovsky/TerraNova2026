<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\Marketing\Infrastructure\Meta\MetaAdsReadClient;
use Domains\Marketing\Infrastructure\Meta\MetaReadTransportInterface;

$transport=new class implements MetaReadTransportInterface {
 public array $urls=[];
 public function get(string $url,string $token):array {
  $this->urls[]=$url;
  if($token!=='test-secret')throw new RuntimeException('Token propagation mismatch.');
  return ['data'=>[['id'=>'111','name'=>'Imported campaign']],'paging'=>['next'=>'continue','cursors'=>['after'=>'test123']]];
 }
};
$client=new MetaAdsReadClient($transport);
$first=$client->campaigns('123','test-secret');
if($first['next_cursor']!=='test123')throw new RuntimeException('Cursor not retained.');
$second=$client->campaigns('act_123','test-secret','test123');
if(!str_contains($transport->urls[1],'after=test123'))throw new RuntimeException('Cursor not encoded.');
foreach($transport->urls as $url) {
 if(!str_starts_with($url,'https://graph.facebook.com/v27.0/act_123/'))throw new RuntimeException('Untrusted URL generated.');
 if(str_contains($url,'test-secret'))throw new RuntimeException('Secret leaked into URL.');
}
$client->insights('123','test-secret','campaign','2026-11-01','2026-11-15');
$bad=[
 fn()=>$client->insights('123','test-secret','campaign','2026-11-01','2027-01-01'),
 fn()=>$client->insights('123','test-secret','campaign','2026-11-16','2026-11-01'),
 fn()=>$client->insights('123','test-secret','ad','2026-02-30','2026-03-01'),
 fn()=>$client->campaigns('act_123/ads','test-secret'),
 fn()=>$client->ads('123','test-secret',str_repeat('x',513)),
];
foreach($bad as $case){
 try{$case();throw new RuntimeException('Invalid Meta input was accepted.');}
 catch(InvalidArgumentException){}
}
echo "marketing_meta_client_contract OK\n";
