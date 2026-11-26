<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\Marketing\Infrastructure\Meta\CurlMetaHttpTransport;
use Domains\Marketing\Infrastructure\Meta\MetaGraphReadAdapter;

$transport=new CurlMetaHttpTransport();
foreach(['http://graph.facebook.com/v27.0/me','https://evil.test/x','https://graph.facebook.com:444/x','https://user@graph.facebook.com/x'] as $url){
    try{$transport->get($url,'fixture');}catch(RuntimeException){continue;}
    throw new RuntimeException('Unapproved Meta transport host accepted: '.$url);
}
// Domain boundary has no external write contract or network write method.
$provider=new ReflectionClass(MetaGraphReadAdapter::class);
foreach(['createPausedCampaign','activate','pause','updateBudget','setDeliveryStatus'] as $forbidden){
    if($provider->hasMethod($forbidden))throw new RuntimeException('Unsafe provider write method exposed: '.$forbidden);
}
echo "Marketing Meta read security boundary PASS\n";


$vault=new class implements \Platform\Integration\Contract\CredentialVaultInterface {
    public function resolve(\Platform\Integration\Model\Credential $credential):array{return ['token'=>'fixture'];}
};
$circuits=new class implements \Kernel\Resilience\Contract\CircuitBreakerStoreInterface {
    public function assertAvailable(?string $organizationId,string $serviceKey):void{}
    public function recordSuccess(?string $organizationId,string $serviceKey):void{}
    public function recordRetryableFailure(?string $organizationId,string $serviceKey,int $failureThreshold,int $openSeconds):void{}
};
$study=['id'=>'700','type'=>'SPLIT_TEST','created_time'=>'2026-01-01T00:00:00+0000','start_time'=>'2026-01-02T00:00:00+0000','end_time'=>'2026-01-09T00:00:00+0000'];
$fixture=[
    '100/ad_studies'=>['data'=>[$study]],
    '200/ad_studies'=>['data'=>[$study]],
    '700/cells'=>['data'=>[
        ['id'=>'701','ad_entities_count'=>1,'control_percentage'=>0,'treatment_percentage'=>50],
        ['id'=>'702','ad_entities_count'=>1,'control_percentage'=>0,'treatment_percentage'=>50],
    ]],
    '701/campaigns'=>['data'=>[['id'=>'100','account_id'=>'12345']]],
    '702/campaigns'=>['data'=>[['id'=>'200','account_id'=>'12345']]],
];
$mock=new class implements \Domains\Marketing\Infrastructure\Meta\MetaHttpTransport {
    public array $fixture=[],$calls=[];
    public function get(string $url,string $accessToken):array{
        $path=substr((string)parse_url($url,PHP_URL_PATH),7);
        $this->calls[]=$path;
        if($accessToken!=='fixture'||!isset($this->fixture[$path]))throw new RuntimeException('Unexpected fixture read.');
        return $this->fixture[$path];
    }
};
$mock->fixture=$fixture;
$adapter=new MetaGraphReadAdapter($vault,$mock,new \Kernel\Resilience\ExternalCallExecutor($circuits));
$read=static fn(string $studyId='700')=>$adapter->inspectSplitStudy('org-a','act_12345','env://META_TEST',$studyId,'100','200','2026-01-02','2026-01-08');
$result=$read();
if($result['control']['cell_id']!=='701'||$result['candidate']['cell_id']!=='702'||!$result['provider_split_configuration_verified']||$result['provider_results_available'])
    throw new RuntimeException('Study readback failed.');
foreach([
    [...$fixture,'200/ad_studies'=>['data'=>[]]],
    [...$fixture,'701/campaigns'=>['data'=>[['id'=>'100','account_id'=>'99999']]]],
    [...$fixture,'702/campaigns'=>['data'=>[['id'=>'100','account_id'=>'12345']]]],
    [...$fixture,'100/ad_studies'=>['data'=>[[...$study,'canceled_time'=>'2026-01-03T00:00:00Z']]]],
    [...$fixture,'100/ad_studies'=>['data'=>[[...$study,'end_time'=>'2026-01-10T00:00:00Z']]]],
    [...$fixture,'700/cells'=>['data'=>[[...$fixture['700/cells']['data'][0],'treatment_percentage'=>51],$fixture['700/cells']['data'][1]]]],
] as $bad){
    $mock->fixture=$bad;
    try{$read();}catch(DomainException){continue;}
    throw new RuntimeException('Invalid study association or configuration accepted.');
}
$mock->fixture=$fixture;$mock->calls=[];
try{$read('999');throw new RuntimeException('Unlinked study accepted.');}catch(DomainException){}
if($mock->calls!==['100/ad_studies'])throw new RuntimeException('Unverified study ID was dereferenced.');
echo "Marketing Meta linked split-study binding, time and account fences PASS\n";
