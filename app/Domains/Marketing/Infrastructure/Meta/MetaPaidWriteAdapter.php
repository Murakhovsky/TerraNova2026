<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

use DomainException;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;

/** P0 provider primitives, intentionally not registered as live runtime services.
 * Caller must be an approved, journaled orchestration service. No generic Graph API exposed.
 */
final readonly class MetaPaidWriteAdapter
{
    public function __construct(private CredentialVaultInterface $vault,private MetaWriteTransport $transport){}

    public function campaign(string $org,string $account,string $ref,string $name):string
    {
        return $this->created($this->call($org,$account,$ref,'POST',$account.'/campaigns',[
            'name'=>$this->name($name),'objective'=>'OUTCOME_TRAFFIC','status'=>'PAUSED',
            'special_ad_categories'=>'[]','is_adset_budget_sharing_enabled'=>'false'
        ]));
    }

    /** Only explicitly reviewed general retail traffic; no arbitrary targeting, bid, currency or active status. */
    public function adset(string $org,string $account,string $ref,string $campaign,string $name,int $lifetimeMinor,
        string $start,string $end,array $countries):string
    {
        $this->id($campaign);$this->period($start,$end);
        if($lifetimeMinor<1||$lifetimeMinor>1_000_000_000_000||!array_is_list($countries)||count($countries)<1
            ||count($countries)>20||count(array_unique($countries))!==count($countries))throw new DomainException('Invalid P0 adset scope.');
        foreach($countries as $country){if(!is_string($country)||!preg_match('/^[A-Z]{2}$/D',$country))throw new DomainException('Invalid market country.');}
        $parent=$this->read($org,$account,$ref,'campaign',$campaign);
        if($parent['configured_status']!=='PAUSED')throw new DomainException('Parent campaign must be paused.');
        return $this->created($this->call($org,$account,$ref,'POST',$account.'/adsets',[
            'name'=>$this->name($name),'campaign_id'=>$campaign,'status'=>'PAUSED','lifetime_budget'=>$lifetimeMinor,
            'start_time'=>$start,'end_time'=>$end,'billing_event'=>'IMPRESSIONS','optimization_goal'=>'LINK_CLICKS',
            'bid_strategy'=>'LOWEST_COST_WITHOUT_CAP','destination_type'=>'WEBSITE',
            'targeting'=>json_encode(['geo_locations'=>['countries'=>$countries]],JSON_THROW_ON_ERROR)
        ]));
    }

    /** Reuse an independently accepted existing Creative. Never upload media or invent a Page identity. */
    public function ad(string $org,string $account,string $ref,string $adset,string $creative,string $name):string
    {
        $this->id($adset);$this->id($creative);
        $parent=$this->read($org,$account,$ref,'adset',$adset);
        if($parent['configured_status']!=='PAUSED')throw new DomainException('Parent adset must be paused.');
        $this->read($org,$account,$ref,'creative',$creative);
        return $this->created($this->call($org,$account,$ref,'POST',$account.'/ads',[
            'name'=>$this->name($name),'adset_id'=>$adset,'status'=>'PAUSED',
            'creative'=>json_encode(['creative_id'=>$creative],JSON_THROW_ON_ERROR)
        ]));
    }

    /** Read actual account and configured status. Effective status is NOT proof of spend or delivery. */
    public function read(string $org,string $account,string $ref,string $kind,string $id):array
    {
        $this->id($id);
        $fields=match($kind){
            'campaign'=>'id,account_id,configured_status,effective_status,objective,special_ad_categories,lifetime_budget,daily_budget',
            'adset'=>'id,account_id,campaign_id,configured_status,effective_status,lifetime_budget,daily_budget,start_time,end_time',
            'ad'=>'id,account_id,adset_id,configured_status,effective_status,creative{id}',
            'creative'=>'id,account_id',
            default=>throw new DomainException('Unknown Meta readback kind.')
        };
        $row=$this->call($org,$account,$ref,'GET',$id,['fields'=>$fields]);
        if((string)($row['id']??'')!==$id||(string)($row['account_id']??'')!==substr($account,4)
            ||($kind!=='creative'&&!in_array($row['configured_status']??null,['PAUSED','ACTIVE','DELETED','ARCHIVED'],true))){
            throw new DomainException('Meta object account or identity mismatch.');
        }
        return $row;
    }

    /** Status-only primitive. No budget increases, deletes or unrelated object updates. */
    public function status(string $org,string $account,string $ref,string $kind,string $id,string $desired):array
    {
        if(!in_array($kind,['campaign','adset','ad'],true)||!in_array($desired,['PAUSED','ACTIVE'],true))
            throw new DomainException('Unsupported Meta status mutation.');
        $this->read($org,$account,$ref,$kind,$id);
        $result=$this->call($org,$account,$ref,'POST',$id,['status'=>$desired]);
        if(($result['success']??null)!==true)throw new DomainException('Meta status acknowledgement missing.');
        $after=$this->read($org,$account,$ref,$kind,$id);
        if($after['configured_status']!==$desired)throw new DomainException('Meta configured status not confirmed.');
        return $after;
    }

    private function call(string $org,string $account,string $ref,string $method,string $path,array $fields):array
    {
        if(!preg_match('/^act_[0-9]{1,30}$/D',$account)||!preg_match('/^env:\\/\\/[A-Z][A-Z0-9_]{2,127}$/D',$ref))
            throw new DomainException('Dedicated Meta write credential required.');
        $credential=new Credential('mkt-meta-write-'.substr(hash('sha256',$org.':'.$account),0,24),
            OrganizationId::fromString($org),$account,'meta_ads_write',$ref,['ads_management']);
        $material=$this->vault->resolve($credential);$token=$material['token']??null;
        if(!is_string($token)||$token===''||strlen($token)>16384||preg_match('/[\\x00-\\x20\\x7f]/',$token))
            throw new DomainException('Meta write credential unavailable.');
        return $this->transport->request($method,$path,$fields,$token);
    }
    private function created(array $response):string
    {
        $id=$response['id']??null;if(!is_string($id))throw new DomainException('Meta create receipt missing.');
        return $this->id($id);
    }
    private function id(string $id):string
    {
        if(!preg_match('/^[0-9]{1,30}$/D',$id))throw new DomainException('Invalid provider object ID.');
        return $id;
    }
    private function name(string $name):string
    {
        if(mb_strlen(trim($name))<3||mb_strlen($name)>180)throw new DomainException('Invalid Meta name.');
        return $name;
    }
    private function period(string $start,string $end):void
    {
        $a=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z',$start,new \DateTimeZone('UTC'));
        $b=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z',$end,new \DateTimeZone('UTC'));
        if(!$a||!$b||$a->format('Y-m-d\\TH:i:s\\Z')!==$start||$b->format('Y-m-d\\TH:i:s\\Z')!==$end
            ||$a>=$b||$b->getTimestamp()-$a->getTimestamp()>31*86400)throw new DomainException('Bounded UTC delivery interval required.');
    }
}
