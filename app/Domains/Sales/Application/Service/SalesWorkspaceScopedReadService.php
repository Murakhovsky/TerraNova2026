<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Domains\Sales\Model\LeadStatus;
use DomainException;

/** Redacted, bounded read projection over the existing native workspace. */
final readonly class SalesWorkspaceScopedReadService
{
    public function __construct(private SalesWorkspaceScopeInterface $scope,private SalesWorkspaceReadModelInterface $read){}
    public function list(string $org,int $actor,string $kind,array $input):array
    {
        $owner=$this->owner($org,$actor,$input);
        $allowed=['scope','owner_id','status','limit','offset'];
        if($kind==='deal')$allowed=[...$allowed,'pipeline_id','stage_id'];
        if(!in_array($kind,['lead','deal'],true)||array_diff(array_keys($input),$allowed)!==[])throw new DomainException('Invalid workspace filters.');
        $limit=$input['limit']??50;$offset=$input['offset']??0;
        if(!is_int($limit)||$limit<1||$limit>100||!is_int($offset)||$offset<0||$offset>100000)throw new DomainException('Invalid workspace page.');
        $filters=['owner_id'=>$owner,'limit'=>$limit+1,'offset'=>$offset,'sort'=>'created_at','direction'=>'desc'];
        if(array_key_exists('status',$input)){
            $states=$kind==='lead'?LeadStatus::values():['active','paused','closed','lost'];
            if(!in_array($input['status'],$states,true))throw new DomainException('Invalid workspace status.');
            $filters['status']=$input['status'];
        }
        foreach(['pipeline_id','stage_id'] as $key){
            if(!array_key_exists($key,$input))continue;
            if(!is_string($input[$key])||preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,79}$/D',$input[$key])!==1)throw new DomainException('Invalid pipeline filter.');
            $filters[$key]=$input[$key];
        }
        $rows=$kind==='lead'?$this->read->leads($org,$filters):$this->read->deals($org,$filters);
        $more=count($rows)>$limit;$items=[];
        foreach(array_slice($rows,0,$limit) as $row){
            $assigned=$row['owner_id']===null?null:(int)$row['owner_id'];
            if($owner!==null&&$assigned!==$owner)throw new DomainException('Native owner scope mismatch.');
            $item=['id'=>(int)$row['id'],'owner_id'=>$assigned,'status'=>$row['status'],'created_at'=>$row['created_at'],'next_action_at'=>$row['next_action_at']??null];
            if($kind==='lead')$item['deal_id']=empty($row['deal_id'])?null:(int)$row['deal_id'];
            else $item=[...$item,'pipeline_id'=>$row['pipeline_id']??null,'stage_id'=>$row['stage_id']??null,'deal_value'=>$row['deal_value']===null?null:(string)$row['deal_value'],'currency'=>$row['currency']??null];
            $items[]=$item;
        }
        return ['owner_id'=>$owner,'items'=>$items,'limit'=>$limit,'offset'=>$offset,'has_more'=>$more];
    }
    public function timeline(string $org,int $actor,array $input):array
    {
        if(array_diff(array_keys($input),['scope','owner_id','deal_id','limit'])!==[])throw new DomainException('Invalid timeline filters.');
        $owner=$this->owner($org,$actor,$input);$id=$input['deal_id']??null;$limit=$input['limit']??50;
        if(!is_int($id)||$id<1||!is_int($limit)||$limit<1||$limit>100)throw new DomainException('Invalid timeline identity.');
        $row=$this->read->deal($org,$id);
        if($row===null||(int)$row['id']!==$id||$row['organization_id']!==$org||($owner!==null&&(int)$row['owner_id']!==$owner))return ['found'=>false,'items'=>[]];
        $items=[];foreach($this->read->timeline($org,$id,$limit) as $item){
            $type=$item['item_type'];
            // Unknown foreign event/action names are not Sales evidence.
            if(in_array($type,['EVENT','ACTION'],true)&&!str_starts_with((string)$item['subtype'],'sales.'))continue;
            $items[]=['id'=>(string)$item['id'],'item_type'=>$type,'subtype'=>(string)$item['subtype'],'occurred_at'=>(string)$item['occurred_at'],'status'=>$item['status']??null];
        }
        // Re-attest current owner after projection, rather than returning another owner's history.
        $now=$this->read->deal($org,$id);
        if($now===null||$now['organization_id']!==$org||($owner!==null&&(int)$now['owner_id']!==$owner))return ['found'=>false,'items'=>[]];
        return ['found'=>true,'items'=>$items];
    }
    private function owner(string $org,int $actor,array $input):?int
    {
        $mode=$input['scope']??'self';$owner=$input['owner_id']??null;
        if(!in_array($mode,['self','owner','tenant'],true)||(array_key_exists('owner_id',$input)&&(!is_int($owner)||$owner<1)))throw new DomainException('Invalid scope.');
        return $this->scope->resolve($org,$actor,$mode,$owner);
    }
}
