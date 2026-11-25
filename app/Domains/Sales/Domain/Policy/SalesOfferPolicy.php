<?php
declare(strict_types=1);
namespace Domains\Sales\Domain\Policy;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Sales\Model\SalesCurrency;

final class SalesOfferPolicy
{
    public static function transition(string $from,string $to):bool
    {
        return in_array($to,match($from){
            'draft'=>['presented','withdrawn','expired'],
            'presented'=>['accepted','rejected','withdrawn','expired'],
            default=>[],
        },true);
    }

    public static function terms(array $in):array
    {
        $title=$in['title']??null;$currency=$in['currency']??null;$lines=$in['lines']??null;
        if (!is_string($title)||trim($title)!==$title||$title===''||mb_strlen($title)>180
            ||!is_string($currency)||!SalesCurrency::accepts($currency)||!is_array($lines)||!array_is_list($lines)||count($lines)<1||count($lines)>20) throw new DomainException('Invalid offer terms.');
        $date=self::date($in['valid_until']??null);
        $total=0;$normalized=[];
        foreach($lines as $line){
            if(!is_array($line))throw new DomainException('Invalid offer line.');
            $kind=$line['kind']??null;$name=$line['title']??null;$qty=$line['quantity']??null;$price=$line['unit_price']??null;
            $required=match($kind){
                'service'=>[],
                'product'=>['product_id','variant_id'],
                'property'=>['inventory_id'],
                default=>throw new DomainException('Unknown offer line kind.'),
            };
            if (array_diff(array_keys($line),['kind','title','quantity','unit_price',...$required])!==[]
                ||!is_string($name)||trim($name)!==$name||$name===''||mb_strlen($name)>180||!is_int($qty)||$qty<1||$qty>10000
                ||($kind==='property'&&$qty!==1)||!is_string($price)||preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$price)!==1) throw new DomainException('Invalid quoted line terms.');
            $row=['kind'=>$kind,'title'=>$name,'quantity'=>$qty,'unit_price'=>$price];
            foreach($required as $key){
                if(!is_string($line[$key]??null)||preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$line[$key])!==1)throw new DomainException('Invalid source reference.');
                $row[$key]=$line[$key];
            }
            $minor=(int)str_replace('.','',$price);
            if($minor>intdiv(99999999999999,$qty))throw new DomainException('Quote exceeds monetary bounds.');
            $amount=$minor*$qty;
            if($total>99999999999999-$amount)throw new DomainException('Quote total exceeds monetary bounds.');
            $total+=$amount;$row['line_total']=self::money($amount);$normalized[]=$row;
        }
        if($total<1)throw new DomainException('Positive offer total required.');
        return ['title'=>$title,'currency'=>$currency,'total'=>self::money($total),'valid_until'=>$date->format('Y-m-d H:i:s'),'lines'=>$normalized];
    }

    public static function date(mixed $raw):DateTimeImmutable
    {
        if(!is_string($raw)||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D',$raw)!==1)throw new DomainException('Explicit RFC3339 expiration required.');
        $d=new DateTimeImmutable($raw);$errors=DateTimeImmutable::getLastErrors();
        if($errors!==false&&($errors['warning_count']||$errors['error_count']))throw new DomainException('Invalid expiration.');
        return $d->setTimezone(new DateTimeZone('UTC'));
    }

    public static function money(int $minor):string{return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);}
}
