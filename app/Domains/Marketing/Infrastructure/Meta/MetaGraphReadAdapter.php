<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

use DateTimeImmutable;
use DomainException;
use Domains\Marketing\Application\Contract\PaidMediaProviderInterface;
use Kernel\Resilience\ExternalCallExecutor;
use Kernel\Resilience\ExternalCallPolicy;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;

/**
 * Version-pinned, paginated, bounded Meta Marketing API read adapter.
 * Accepts only opaque Vault refs, never inline or request-supplied access tokens.
 */
final readonly class MetaGraphReadAdapter implements PaidMediaProviderInterface
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

    /** @param array<string,string> $parameters @return list<array<string,mixed>> */
    private function pages(string $org,string $account,string $ref,string $path,array $parameters):array
    {
        $items=[];$after=null;$seen=[];
        for($page=0;$page<self::MAX_PAGES;$page++){
            $query=$parameters;
            if($after!==null)$query['after']=$after;
            $response=$this->request($org,$account,$ref,$path,$query);
            if(!isset($response['data'])||!is_array($response['data'])||!array_is_list($response['data']))throw new DomainException('Meta page missing array data.');
            foreach($response['data'] as $row){
                if(!is_array($row)||!isset($row['id'])&&$path!==$account.'/insights')throw new DomainException('Malformed Meta page row.');
                $items[]=$row;
                if(count($items)>1000)throw new DomainException('Meta sync exceeds bounded object count; use smaller scope.');
            }
            $cursor=$response['paging']['cursors']['after']??null;
            if(!isset($response['paging']['next'])||!is_string($cursor)||$cursor==='')return $items;
            if(strlen($cursor)>1000||isset($seen[$cursor]))throw new DomainException('Invalid or repeated Meta cursor.');
            $seen[$cursor]=true;$after=$cursor;
        }
        throw new DomainException('Meta page bound reached; sync is incomplete, nothing must be promoted.');
    }

    /** @param array<string,string> $params @return array<string,mixed> */
    private function request(string $org,string $account,string $ref,string $path,array $params):array
    {
        if(!preg_match('#^act_[0-9]{1,30}(?:/(?:campaigns|adsets|ads|insights))?$#D',$path))throw new DomainException('Meta path not allowlisted.');
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
