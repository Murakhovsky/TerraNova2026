<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;

use DomainException;

/** Each stream/instrument keeps its OWN freshness, sequence and recovery epoch. */
final readonly class PerInstrumentStreamTrustMachine
{
    /** @param array<string,mixed>|null $previous
     * @return array{status:string,reason:string,sequence:?string,recovery_required:int}
     */
    public function decide(?array $previous,string $channel,string $type,?string $sequence,
        bool $qualityTrusted,int $epoch):array
    {
        if($epoch<1 || !in_array($channel,['BBO','L2'],true)
           || !in_array($type,['BBO','ORDER_BOOK_SNAPSHOT','ORDER_BOOK_DELTA'],true)
           || ($type==='BBO')!==($channel==='BBO')){
            throw new DomainException('Invalid per-symbol stream evidence.');
        }
        if($sequence!==null && (strlen($sequence)>40 || !ctype_digit($sequence))){
            return ['status'=>'UNTRUSTED','reason'=>'SEQUENCE_INVALID',
                'sequence'=>$previous['last_sequence']??null,'recovery_required'=>1];
        }
        $prior=($previous!==null && (int)$previous['fencing_token']===$epoch)
            ?$previous:null;
        $old=$prior['last_sequence']??null;
        $recover=$prior===null || (int)($prior['recovery_required']??1)===1
            || ($prior['status']??'UNTRUSTED')!=='TRUSTED';
        $reason='OK';
        if(!$qualityTrusted)$reason='QUALITY_UNTRUSTED';
        elseif($channel==='L2' && $recover && $type!=='ORDER_BOOK_SNAPSHOT')
            $reason='L2_SNAPSHOT_REQUIRED';
        elseif($channel==='L2' && $sequence===null)
            $reason='L2_SEQUENCE_REQUIRED';
        elseif($old!==null && $sequence!==null && (!$recover || $channel==='BBO')
            && self::cmp($sequence,(string)$old)<=0)
            $reason='NONMONOTONIC_SEQUENCE';
        elseif($channel==='L2' && !$recover && $type==='ORDER_BOOK_DELTA'
            && $old!==null && $sequence!==null
            && self::cmp($sequence,self::increment((string)$old))!==0)
            $reason='L2_SEQUENCE_GAP';
        $ok=$reason==='OK';
        return ['status'=>$ok?'TRUSTED':'UNTRUSTED','reason'=>$reason,
            'sequence'=>$ok?$sequence:$old,'recovery_required'=>$ok?0:1];
    }
    private static function cmp(string $a,string $b):int
    {
        if(!ctype_digit($a)||!ctype_digit($b))throw new DomainException('Numeric sequence required.');
        $a=ltrim($a,'0')?:'0';$b=ltrim($b,'0')?:'0';
        return strlen($a)===strlen($b)?strcmp($a,$b)<=>0:strlen($a)<=>strlen($b);
    }
    private static function increment(string $s):string
    {
        if(!ctype_digit($s))throw new DomainException('Invalid sequence increment.');
        $digits=str_split(ltrim($s,'0')?:'0');
        for($i=count($digits)-1;$i>=0;--$i){
            if($digits[$i]!=='9'){$digits[$i]=(string)((int)$digits[$i]+1);return implode('',$digits);}
            $digits[$i]='0';
        }
        return '1'.implode('',$digits);
    }
}
