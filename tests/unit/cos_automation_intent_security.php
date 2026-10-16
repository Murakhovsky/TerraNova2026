<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($root):void {
    if (!str_starts_with($class,'App\\')) return;
    $file=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if (is_file($file)) require $file;
});
use App\Application\Capability\AutomationIntentSignatureVerifier;

$ai=str_repeat('a',40);
$cron=str_repeat('c',40);
$verifier=new AutomationIntentSignatureVerifier($ai,$cron,'tenant-1');
$time='1792155600';
$nonce='ABCDEFGHIJKLMNOP0123456789';
$body='{"capability_id":"property.bulk.create","items":[]}';
$sign=static fn(string $origin,string $secret,string $payload):string=>hash_hmac(
    'sha256',implode("\n",[$origin,$time,$nonce,$payload]),$secret);
$verifier->verify('ai',$time,$nonce,$sign('ai',$ai,$body),$body,(int)$time);
$verifier->verify('cron',$time,$nonce,$sign('cron',$cron,$body),$body,(int)$time);
$deny=static function(callable $call):void {
    try { $call(); } catch(DomainException) { return; }
    throw new RuntimeException('Machine envelope trust boundary did not fail closed.');
};
$deny(fn()=>$verifier->verify('ai',$time,$nonce,$sign('ai',$ai,$body).'X',$body,(int)$time));
$deny(fn()=>$verifier->verify('ai',$time,$nonce,$sign('cron',$cron,$body),$body,(int)$time));
$deny(fn()=>$verifier->verify('cron',$time,$nonce,$sign('ai',$ai,$body),$body,(int)$time));
$deny(fn()=>$verifier->verify('ai',$time,$nonce,$sign('ai',$ai,$body),$body,(int)$time+360));
$deny(fn()=>$verifier->verify('ai',$time,'short',$sign('ai',$ai,$body),$body,(int)$time));
$deny(fn()=>$verifier->verify('agent',$time,$nonce,$sign('ai',$ai,$body),$body,(int)$time));
$deny(fn()=>(new AutomationIntentSignatureVerifier('','',''))->organization());
$deny(fn()=>(new AutomationIntentSignatureVerifier('short','','tenant-1'))
    ->verify('ai',$time,$nonce,$sign('ai',$ai,$body),$body,(int)$time));
$store=file_get_contents($root.'/symfony/src/Persistence/Capability/CanonicalAutomationIntentStore.php');
if (!str_contains($store,'$this->property->stage(')
    || !str_contains($store,'pending_review')
    || str_contains($store,'->advance(')
    || str_contains($store,'->executeApproved(')
    || str_contains($store,'cos_workflow_runs')
    || str_contains($store,'cos_workflow_steps')) {
    throw new RuntimeException('Machine proposals must not execute Actions or write technical Runs/Steps.');
}
echo "COS AI/CRON signed intent boundary PASS.\n";
