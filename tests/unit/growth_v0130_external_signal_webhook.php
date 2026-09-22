<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Application\Growth\Integration\GrowthExternalSignalWebhook;
use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;

function expectGrowthV0130(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$growth=new class implements GrowthApplicationBoundary {
    /** @var array<string,mixed> */
    public array $last=[];
    public bool $replay=false;

    public function detectSignal(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array{return [];}
    public function ingestExternalSignal(string $organizationId,int $actorId,string $correlationId,string $source,string $idempotencyKey,array $input):array
    {
        $this->last=[
            'organization_id'=>$organizationId,'actor_id'=>$actorId,'correlation_id'=>$correlationId,
            'source'=>$source,'idempotency_key'=>$idempotencyKey,'input'=>$input,
        ];
        return ['signal_id'=>'GSIG-EXTERNAL-1']+($this->replay?['replayed'=>true]:[]);
    }
    public function detectCandidate(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array{return [];}
    public function researchCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey,array $input):array{return [];}
    public function scoreCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey,array $input):array{return [];}
    public function qualifyCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $reason,string $idempotencyKey):array{return [];}
    public function monitorCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $reason,string $idempotencyKey):array{return [];}
    public function disqualifyCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $reason,string $idempotencyKey):array{return [];}
    public function prepareHandoff(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey,array $input):array{return [];}
    public function viewSignal(string $organizationId,string $signalId):?array{return null;}
    public function viewCandidate(string $organizationId,string $candidateId):?array{return null;}
};

$states=new class implements ModuleStateRepositoryInterface {
    /** @var array<string,bool> */
    private array $values=[];
    public function enabledOverride(string $organizationId,string $moduleId):?bool
    {
        return $this->values[$organizationId.':'.$moduleId]??null;
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void
    {
        $this->values[$organizationId.':'.$moduleId]=$enabled;
    }
};
$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('growth','Growth','0.13.0',enabledByDefault:true,schemaVersion:'0.8.0'),
]),$states);

$secret='growth-webhook-fixture-secret';
$webhook=new GrowthExternalSignalWebhook($growth,$modules,$secret,42,300);
$payload=[
    'organization_id'=>'org-1',
    'source'=>'N8N',
    'signal'=>[
        'subject_type'=>'account',
        'subject_id'=>'account-42',
        'signal_type'=>'leadership_change',
        'source_reference'=>'https://example.test/event/42',
        'facts'=>['role'=>'COO','change'=>'joined'],
        'confidence'=>0.91,
        'occurred_at'=>'2026-09-22T12:00:00+00:00',
    ],
];
$body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$timestamp=(string)time();
$signature='sha256='.hash_hmac('sha256',$timestamp.'.'.$body,$secret);

$result=$webhook->handle($body,$signature,$timestamp,'external-event-42');
expectGrowthV0130($result['status']===201&&($result['payload']['ok']??false)===true,'Signed Growth webhook must accept a valid signal.');
expectGrowthV0130(($result['payload']['signal_id']??null)==='GSIG-EXTERNAL-1','Growth webhook lost canonical Signal reference.');
expectGrowthV0130(($growth->last['organization_id']??null)==='org-1','Growth webhook lost organization context.');
expectGrowthV0130(($growth->last['actor_id']??null)===42,'Growth webhook lost configured service actor.');
expectGrowthV0130(($growth->last['source']??null)==='n8n','Growth webhook must normalize source.');
expectGrowthV0130(($growth->last['idempotency_key']??null)==='external-event-42','Growth webhook lost external idempotency key.');
expectGrowthV0130(is_string($growth->last['correlation_id']??null)&&$growth->last['correlation_id']!=='','Growth webhook must create correlation id.');

$growth->replay=true;
$replay=$webhook->handle($body,$signature,$timestamp,'external-event-42');
expectGrowthV0130($replay['status']===200&&($replay['payload']['replayed']??false)===true,'Growth webhook replay must be explicit and HTTP 200.');

$badSignature=$webhook->handle($body,'sha256='.str_repeat('0',64),$timestamp,'external-event-43');
expectGrowthV0130($badSignature['status']===401,'Growth webhook must reject invalid HMAC.');

$expiredTimestamp=(string)(time()-1000);
$expiredSignature='sha256='.hash_hmac('sha256',$expiredTimestamp.'.'.$body,$secret);
$expired=$webhook->handle($body,$expiredSignature,$expiredTimestamp,'external-event-44');
expectGrowthV0130($expired['status']===401,'Growth webhook must reject expired timestamp.');

$states->setEnabled('org-1','growth',false);
$disabled=$webhook->handle($body,$signature,$timestamp,'external-event-45');
expectGrowthV0130($disabled['status']===403,'Growth webhook must reject disabled Growth module.');

$misconfigured=new GrowthExternalSignalWebhook($growth,$modules,'',0,300);
$unavailable=$misconfigured->handle($body,$signature,$timestamp,'external-event-46');
expectGrowthV0130($unavailable['status']===503,'Unconfigured Growth webhook must fail closed.');

echo "Growth V0.13 External Signal Webhook contracts passed.\n";
