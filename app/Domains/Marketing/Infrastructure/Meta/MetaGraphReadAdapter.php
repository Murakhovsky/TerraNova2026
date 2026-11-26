<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

use DateTimeImmutable;
use DomainException;
use Domains\Marketing\Application\Contract\PaidMediaProviderInterface;
use Domains\Marketing\Application\Contract\MarketingExperimentStudyReadPort;
use Kernel\Resilience\ExternalCallExecutor;
use Kernel\Resilience\ExternalCallPolicy;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;

/**
 * Version-pinned, paginated, bounded Meta Marketing API read adapter.
 * Accepts only opaque Vault refs, never inline or request-supplied access tokens.
 */
final readonly class MetaGraphReadAdapter implements PaidMediaProviderInterface, MarketingExperimentStudyReadPort
{
    private const VERSION='v27.0';
    private const MAX_PAGES=10;
    public function __construct(
        private CredentialVaultInterface $vault,
        private MetaHttpTransport $transport,
        private ExternalCallExecutor $resilience,
    ){}

    public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array
    {
        $account=$this->account($providerAccountId);
        $detail=$this->request($organizationId,$account,$credentialRef,$account,[
            'fields'=>'id,name,account_status,currency,timezone_name',
        ]);
        if((string)($detail['id']??'')!==$account)throw new DomainException('Meta account verification mismatch.');
        // A general Graph token is not evidence of ads_read. Probe the Ads API edge.
        $this->request($organizationId,$account,$credentialRef,$account.'/campaigns',['fields'=>'id','limit'=>'1']);
        if(!preg_match('/^[A-Z]{3}$/D',(string)($detail['currency']??'')))throw new DomainException('Meta account currency not verified.');
        $timezone=(string)($detail['timezone_name']??'');
        if($timezone===''||!in_array($timezone,\DateTimeZone::listIdentifiers(),true))throw new DomainException('Meta account timezone not verified.');
        return [
            'provider'=>'meta',
            'ad_account_id'=>$account,
            'currency'=>(string)$detail['currency'],
            'timezone'=>$timezone,
            'connection_status'=>'READ_ONLY',
            'capability_scope'=>['ads_read'],
            'provider_account_status'=>(string)($detail['account_status']??'unknown'),
        ];
    }

    public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array
    {
        $account=$this->account($providerAccountId);
        return [
            'campaigns'=>$this->pages($organizationId,$account,$credentialRef,$account.'/campaigns',[
                'fields'=>'id,name,objective,status,effective_status,updated_time','limit'=>'100',
            ]),
            'adsets'=>$this->pages($organizationId,$account,$credentialRef,$account.'/adsets',[
                'fields'=>'id,name,campaign_id,status,effective_status,updated_time','limit'=>'100',
            ]),
            'ads'=>$this->pages($organizationId,$account,$credentialRef,$account.'/ads',[
                'fields'=>'id,name,adset_id,creative{id},status,effective_status,updated_time','limit'=>'100',
            ]),
        ];
    }

    public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array
    {
        $account=$this->account($providerAccountId);
        if(!in_array($grain,['account','campaign','adset','ad'],true))throw new DomainException('Invalid insights grain.');
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
        $end=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if(!$start||!$end||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to
            ||$start>$end||$start->diff($end)->days>30)throw new DomainException('Insights require an explicit bounded date window.');
        return $this->pages($organizationId,$account,$credentialRef,$account.'/insights',[
            'level'=>$grain,
            'time_range'=>json_encode(['since'=>$from,'until'=>$to],JSON_THROW_ON_ERROR),
            'fields'=>'account_id,campaign_id,adset_id,ad_id,spend,impressions,clicks,reach,actions,action_values,date_start,date_stop',
            'limit'=>'100',
        ]);
    }

    /** Read only linked classic split studies, never arbitrary provider objects. */
    public function inspectSplitStudy(string $org,string $providerAccountId,string $ref,string $studyId,string $controlCampaign,string $candidateCampaign,string $from,string $to):array
    {
        $account=$this->account($providerAccountId);
        foreach([$studyId,$controlCampaign,$candidateCampaign] as $id)$this->numericId($id);
        if($controlCampaign===$candidateCampaign)throw new DomainException('Distinct study campaigns required.');
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$from,new \DateTimeZone('UTC'));
        $end=DateTimeImmutable::createFromFormat('!Y-m-d',$to,new \DateTimeZone('UTC'));
        if(!$start||!$end||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to||$start>$end)
            throw new DomainException('Invalid registered study window.');
        $end=$end->modify('+1 day');
        $studies=[];
        foreach([$controlCampaign,$candidateCampaign] as $campaign){
            $rows=$this->pages($org,$account,$ref,$campaign.'/ad_studies',[
                'fields'=>'id,type,created_time,start_time,end_time,canceled_time','limit'=>'100',
            ],true);
            $matches=array_values(array_filter($rows,static fn(array $row):bool=>($row['id']??null)===$studyId));
            if(count($matches)!==1)throw new DomainException('Split study is not uniquely linked to both native campaigns.');
            $row=$matches[0];
            if(($row['type']??null)!=='SPLIT_TEST'||!empty($row['canceled_time']))
                throw new DomainException('Uncanceled classic split study required.');
            $created=$this->studyTimestamp($row['created_time']??null);
            $actualStart=$this->studyTimestamp($row['start_time']??null);
            $actualEnd=$this->studyTimestamp($row['end_time']??null);
            if($created>$start->getTimestamp()||$actualStart!==$start->getTimestamp()||$actualEnd!==$end->getTimestamp())
                throw new DomainException('Study timeline differs from pre-registered window.');
            $studies[]=['study_id'=>$studyId,'type'=>'SPLIT_TEST','created_at_utc'=>gmdate('Y-m-d\\TH:i:s\\Z',$created),
                'start_at_utc'=>gmdate('Y-m-d\\TH:i:s\\Z',$actualStart),'end_at_utc'=>gmdate('Y-m-d\\TH:i:s\\Z',$actualEnd)];
        }
        if($studies[0]!==$studies[1])throw new DomainException('Study associations disagree.');
        $cells=$this->pages($org,$account,$ref,$studyId.'/cells',[
            'fields'=>'id,ad_entities_count,control_percentage,treatment_percentage','limit'=>'100',
        ],true);
        if(count($cells)!==2)throw new DomainException('Exactly two study cells required.');
        $byCampaign=[];$seen=[];
        foreach($cells as $cell){
            $id=$cell['id']??null;
            if(!is_string($id))throw new DomainException('Invalid split study cell.');
            $this->numericId($id);
            if(isset($seen[$id])||!in_array($cell['ad_entities_count']??null,[1,'1'],true)
                ||!in_array($cell['treatment_percentage']??null,[50,50.0,'50','50.0'],true)
                ||!in_array($cell['control_percentage']??null,[null,0,0.0,'0','0.0'],true))
                throw new DomainException('Exact two-arm 50/50 campaign allocation required.');
            $seen[$id]=true;
            $campaigns=$this->pages($org,$account,$ref,$id.'/campaigns',['fields'=>'id,account_id','limit'=>'100'],true);
            if(count($campaigns)!==1)throw new DomainException('Study cell must contain exactly one campaign.');
            $campaign=$campaigns[0]['id']??null;$owner=$campaigns[0]['account_id']??null;
            if(!in_array($campaign,[$controlCampaign,$candidateCampaign],true)||isset($byCampaign[$campaign])
                ||!in_array($owner,[$account,substr($account,4)],true))
                throw new DomainException('Study campaign ownership or allocation differs from registered native arms.');
            $byCampaign[$campaign]=['cell_id'=>$id,'provider_campaign_id'=>$campaign,'allocation_percent'=>50];
        }
        if(!isset($byCampaign[$controlCampaign],$byCampaign[$candidateCampaign]))throw new DomainException('Both registered arms required.');
        return [...$studies[0],'provider_ad_account_id'=>$account,'control'=>$byCampaign[$controlCampaign],
            'candidate'=>$byCampaign[$candidateCampaign],'provider_split_configuration_verified'=>true,'provider_results_available'=>false];
    }

    private function numericId(string $id):void
    {
        if(!preg_match('/^[0-9]{1,30}$/D',$id))throw new DomainException('Invalid Meta object ID.');
    }

    private function studyTimestamp(mixed $value):int
    {
        if(is_int($value)||is_string($value)&&preg_match('/^[0-9]{1,10}$/D',$value))return (int)$value;
        if(!is_string($value)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:?\\d{2})$/D',$value))
            throw new DomainException('Missing or ambiguous study timestamp.');
        $time=new DateTimeImmutable($value);
        if(($errors=DateTimeImmutable::getLastErrors())!==false&&($errors['warning_count']||$errors['error_count']))
            throw new DomainException('Invalid study timestamp.');
        return $time->getTimestamp();
    }

    /** @param array<string,string> $parameters @return list<array<string,mixed>> */
    private function pages(string $org,string $account,string $ref,string $path,array $parameters,bool $studyPath=false):array
    {
        $items=[];$after=null;$seen=[];
        for($page=0;$page<self::MAX_PAGES;$page++){
            $query=$parameters;
            if($after!==null)$query['after']=$after;
            $response=$this->request($org,$account,$ref,$path,$query,$studyPath);
            if(!isset($response['data'])||!is_array($response['data'])||!array_is_list($response['data']))throw new DomainException('Meta page missing array data.');
            foreach($response['data'] as $row){
                if(!is_array($row)||!isset($row['id'])&&$path!==$account.'/insights')throw new DomainException('Malformed Meta page row.');
                $items[]=$row;
                if(count($items)>1000)throw new DomainException('Meta sync exceeds bounded object count; use smaller scope.');
            }
            $cursor=$response['paging']['cursors']['after']??null;
            if(!isset($response['paging']['next']))return $items;
            if(!is_string($cursor)||$cursor==='')throw new DomainException('Incomplete Meta pagination cursor.');
            if(strlen($cursor)>1000||isset($seen[$cursor]))throw new DomainException('Invalid or repeated Meta cursor.');
            $seen[$cursor]=true;$after=$cursor;
        }
        throw new DomainException('Meta page bound reached; sync is incomplete, nothing must be promoted.');
    }

    /** @param array<string,string> $params @return array<string,mixed> */
    private function request(string $org,string $account,string $ref,string $path,array $params,bool $studyPath=false):array
    {
        if(!preg_match('#^act_[0-9]{1,30}(?:/(?:campaigns|adsets|ads|insights))?$#D',$path)
            &&!($studyPath&&preg_match('#^[0-9]{1,30}/(?:ad_studies|cells|campaigns)$#D',$path)))throw new DomainException('Meta path not allowlisted.');
        if(!preg_match('/^env:\/\/[A-Z][A-Z0-9_]{2,127}$/D',$ref))throw new DomainException('Invalid opaque credential reference.');
        $credential=new Credential('marketing-meta-'.substr(hash('sha256',$org.$account),0,24),
            OrganizationId::fromString($org),$account,'meta_ads_read',$ref,['ads_read']);
        $material=$this->vault->resolve($credential);
        $token=$material['token']??'';
        if($token===''||strlen($token)>16384||str_contains($token,"\r")||str_contains($token,"\n"))throw new DomainException('Meta credential unavailable.');
        $url='https://graph.facebook.com/'.self::VERSION.'/'.$path.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
        return $this->resilience->execute($org,'marketing.meta.read.'.substr(hash('sha256',$account),0,16),
            fn():array=>$this->transport->get($url,$token),
            new ExternalCallPolicy(maxAttempts:2,baseDelayMilliseconds:200,maxDelayMilliseconds:800,failureThreshold:4,circuitOpenSeconds:60));
    }

    private function account(string $id):string
    {
        if(!preg_match('/^act_[0-9]{1,30}$/D',$id))throw new DomainException('Invalid Meta Ad Account.');
        return $id;
    }
}
