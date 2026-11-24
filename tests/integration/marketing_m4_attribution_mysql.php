<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');
use Domains\Marketing\Domain\MarketingAttributionPolicy as Policy;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingAttributionStore;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCampaignRepository;
use Domains\Marketing\Domain\MarketingDraftPolicy;
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$org='mkt-m4-'.bin2hex(random_bytes(6));$other='mkt-m4-'.bin2hex(random_bytes(6));
$campaigns=new MysqlMarketingCampaignRepository($db);$store=new MysqlMarketingAttributionStore($db);
$campaign=$campaigns->createDraft($org,'42',(new MarketingDraftPolicy())->validate(['name'=>'M4 integration fixture','business_objective'=>'Native lead attribution']),'m4-campaign-1')['id'];
$lead=['lead_id'=>'42','created_at'=>'2026-01-01 10:00:00','utm_campaign'=>$campaign,'utm_source'=>'google','utm_medium'=>'organic'];
$e=Policy::evidence($lead,$campaign,null);
$h=hash('sha256','capture');
$r=$store->batch($org,'m4-capture-key',$h,fn()=>['result'=>$store->record($org,$campaign,$e)]);
$repeat=$store->batch($org,'m4-capture-key',$h,fn()=>throw new RuntimeException('Replay executed callback.'));
$assert($r===$repeat,'Capture receipt not immutable.');
$deny=false;try{$store->batch($org,'m4-capture-key',str_repeat('f',64),fn()=>[]);}catch(DomainException){$deny=true;}
$assert($deny,'Conflicting capture key accepted.');
$assert($store->record($org,$campaign,$e)['id']===$r['result']['id'],'Source record duplicated.');
$deny=false;try{$store->record($org,$campaign,Policy::evidence([...$lead,'utm_medium'=>'email'],$campaign,null));}catch(DomainException){$deny=true;}
$assert($deny,'Source attribution drift reallocated.');
$assert(count($store->list($org,$campaign,'2026-01-01','2026-01-01',50,0))===1
    &&$store->list($other,$campaign,'2026-01-01','2026-01-01',50,0)===[],'Tenant/window evidence scope incorrect.');
try{$store->batch($org,'m4-rollback-key',$h,function()use($store,$org,$campaign,$lead){
    $store->record($org,$campaign,Policy::evidence([...$lead,'lead_id'=>'43'],$campaign,null));
    throw new RuntimeException('synthetic rollback');
});}catch(RuntimeException $e){if($e->getMessage()!=='synthetic rollback')throw $e;}
$assert(count($store->list($org,$campaign,'2026-01-01','2026-01-01',50,0))===1,'Partial capture committed.');
echo "Marketing M4 native MySQL capture receipt, tenant, idempotency, drift and rollback PASS\n";
