<?php
declare(strict_types=1);
namespace Domains\Sales\Domain\Policy;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
final class SalesOrderTouchHistoryEvidence
{
    public static function normalize(array $e,string $placed):array
    {
        if(count($e)!==4||($e['model']??null)!=='shopify-journey-moments-v1'||!is_array($e['touches']??null)
            ||!array_is_list($e['touches'])||count($e['touches'])<1||count($e['touches'])>100)
            throw new DomainException('Invalid touch history model.');
        $end=self::time($placed);$touches=[];$ids=[];
        foreach($e['touches'] as $v){
            if(!is_array($v)||count($v)!==3||!is_string($v['touch_id']??null)||!preg_match('/^[a-f0-9]{64}$/D',$v['touch_id'])
                ||isset($ids[$v['touch_id']])||!array_key_exists('tags',$v))throw new DomainException('Invalid or duplicate touch.');
            $at=self::time($v['visit_at']??null);
            if($at>$end||$at<$end->modify('-30 days'))throw new DomainException('Touch outside attribution window.');
            $tags=$v['tags'];
            if($tags!==null){
                if(!is_array($tags)||count($tags)!==3||!is_string($tags['utm_campaign']??null)||!preg_match('/^(?:mkt|mkpl)-[a-f0-9]{24}$/D',$tags['utm_campaign']))
                    throw new DomainException('Invalid touch campaign.');
                foreach(['utm_source','utm_medium'] as $k)if(!is_string($tags[$k]??null)||!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$tags[$k]))
                    throw new DomainException('Invalid touch tag.');
                $tags=['utm_source'=>$tags['utm_source'],'utm_medium'=>$tags['utm_medium'],'utm_campaign'=>$tags['utm_campaign']];
            }
            $ids[$v['touch_id']]=$v['visit_at'];$touches[]=['touch_id'=>$v['touch_id'],'visit_at'=>$v['visit_at'],'tags'=>$tags];
        }
        usort($touches,static fn(array $a,array $b):int=>[$a['visit_at'],$a['touch_id']]<=>[$b['visit_at'],$b['touch_id']]);
        $first=$e['first_touch']??null;$last=$e['last_touch']??null;
        if(!is_string($first)||!is_string($last)||($ids[$first]??null)!==$touches[0]['visit_at']
            ||($ids[$last]??null)!==$touches[count($touches)-1]['visit_at']
            ||(count($touches)>1&&$first===$last))throw new DomainException('Touch boundary mismatch.');
        return ['model'=>'shopify-journey-moments-v1','first_touch'=>$first,'last_touch'=>$last,'touches'=>$touches];
    }
    private static function time(mixed $s):DateTimeImmutable
    {
        if(!is_string($s)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D',$s))throw new DomainException('Invalid touch time.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$s,new DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d\TH:i:s.u\Z')!==$s)throw new DomainException('Invalid touch date.');
        return $d;
    }
}
