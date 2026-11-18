<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

use Domains\Marketing\Application\Contract\PaidMediaProviderInterface;
use InvalidArgumentException;
use RuntimeException;

/** Graph API read-only boundary: no mutations, bounded pages, explicit grain/date. */
final readonly class MetaAdsReadClient implements PaidMediaProviderInterface {
 private const ROOT='https://graph.facebook.com/v27.0/';
 public function __construct(private MetaReadTransportInterface $http){}
 public function provider():string{return 'meta';}
 public function campaigns(string $accountId,string $token,?string $cursor=null):array {
  return $this->edge($accountId,'campaigns',$token,
   ['fields'=>'id,name,status,effective_status,objective,updated_time','limit'=>'100'],$cursor);
 }
 public function adSets(string $accountId,string $token,?string $cursor=null):array {
  return $this->edge($accountId,'adsets',$token,
   ['fields'=>'id,name,campaign_id,status,effective_status,updated_time','limit'=>'100'],$cursor);
 }
 public function ads(string $accountId,string $token,?string $cursor=null):array {
  return $this->edge($accountId,'ads',$token,
   ['fields'=>'id,name,adset_id,campaign_id,status,effective_status,updated_time','limit'=>'100'],$cursor);
 }
 public function insights(string $accountId,string $token,string $level,string $since,string $until,?string $cursor=null):array {
  if(!in_array($level,['account','campaign','adset','ad'],true)||!self::date($since)||!self::date($until)||$until<$since) {
   throw new InvalidArgumentException('Invalid Meta insight request.');
  }
  if((new \DateTimeImmutable($since))->diff(new \DateTimeImmutable($until))->days>31)
   throw new InvalidArgumentException('Meta Insights fetch exceeds 31 days.');
  return $this->edge($accountId,'insights',$token,[
   'fields'=>'account_id,campaign_id,adset_id,ad_id,date_start,date_stop,spend,impressions,clicks,reach,actions,action_values',
   'level'=>$level,'time_increment'=>'1',
   'time_range'=>json_encode(['since'=>$since,'until'=>$until],JSON_THROW_ON_ERROR),
   'limit'=>'100'],$cursor);
 }
 private function edge(string $accountId,string $edge,string $token,array $params,?string $cursor):array {
  if(!preg_match('/^(act_)?[0-9]{1,30}$/D',$accountId))throw new InvalidArgumentException('Invalid Meta account ID.');
  if($cursor!==null){
   if(strlen($cursor)>512||!preg_match('~^[A-Za-z0-9_=/+\-]+$~D',$cursor))
    throw new InvalidArgumentException('Invalid Meta page cursor.');
   $params['after']=$cursor;
  }
  $account=str_starts_with($accountId,'act_')?$accountId:'act_'.$accountId;
  $url=self::ROOT.$account.'/'.$edge.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
  $result=$this->http->get($url,$token);
  if(!isset($result['data'])||!is_array($result['data'])||!array_is_list($result['data'])||count($result['data'])>100)
   throw new RuntimeException('Malformed Meta data page.');
  $next=isset($result['paging']['next'])?$result['paging']['cursors']['after']??null:null;
  return ['items'=>array_values(array_filter($result['data'],'is_array')),
   'next_cursor'=>is_string($next)&&$next!==''?$next:null];
 }
 private static function date(string $value):bool {
  $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
  return $date!==false&&$date->format('Y-m-d')===$value;
 }
}
