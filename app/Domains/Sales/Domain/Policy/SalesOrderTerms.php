<?php
declare(strict_types=1);
namespace Domains\Sales\Domain\Policy;

use DomainException;

/** Immutable commercial snapshots in integer minor units; no FX or floating point. */
final class SalesOrderTerms
{
    private const MAX=99999999999999; // DECIMAL(14,2)
    public static function normalize(array $input):array
    {
        if (array_diff(array_keys($input),['currency','lines','discount','tax','shipping','total','deal_id','owner_id','attribution'])!==[])
            throw new DomainException('Unknown order field.');
        $currency=$input['currency']??null;
        if (!in_array($currency,['USD','EUR','UAH'],true)) throw new DomainException('Unsupported order currency.');
        $items=$input['lines']??null;
        if (!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>100) throw new DomainException('Invalid order lines.');
        $lines=[];$subtotal=0;
        foreach ($items as $i=>$line) {
            if (!is_array($line)||array_diff(array_keys($line),['sku','title','quantity','unit_price','product_id','variant_id'])!==[])
                throw new DomainException('Invalid order line.');
            $qty=$line['quantity']??null;
            if (!is_int($qty)||$qty<1||$qty>100000) throw new DomainException('Invalid order quantity.');
            $price=self::minor($line['unit_price']??null);
            if ($price>intdiv(self::MAX,$qty)||$subtotal>self::MAX-$price*$qty) throw new DomainException('Order amount exceeds bound.');
            $sum=$price*$qty;$subtotal+=$sum;
            $product=self::reference($line['product_id']??null);$variant=self::reference($line['variant_id']??null);
            if (($product===null)!==($variant===null)) throw new DomainException('Product and variant must be paired.');
            $lines[]=['line_no'=>$i+1,'sku'=>self::label($line['sku']??null,120),'title'=>self::label($line['title']??null,180),
                'product_id'=>$product,'variant_id'=>$variant,'quantity'=>$qty,'unit_price'=>self::decimal($price),'subtotal'=>self::decimal($sum)];
        }
        $discount=self::minor($input['discount']??'0.00');$tax=self::minor($input['tax']??'0.00');$shipping=self::minor($input['shipping']??'0.00');
        $total=$subtotal-$discount+$tax+$shipping;
        if ($discount>$subtotal||$total>self::MAX||self::minor($input['total']??null)!==$total) throw new DomainException('Order totals do not reconcile.');
        $attrs=$input['attribution']??[];
        if (!is_array($attrs)||($attrs!==[]&&array_is_list($attrs))||array_diff(array_keys($attrs),['touchpoint_id','campaign_id','utm_source','utm_medium','utm_campaign','utm_content','utm_term','click_id'])!==[])
            throw new DomainException('Invalid order attribution observations.');
        $observations=[];
        foreach ($attrs as $key=>$value) {
            // Opaque identifiers / safe campaign tags; never raw URLs or customer details.
            if (!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:~%+-]{0,190}$/D',$value)!==1) throw new DomainException('Invalid attribution observation.');
            $observations[$key]=$value;
        }
        ksort($observations);
        return ['currency'=>$currency,'subtotal'=>self::decimal($subtotal),'discount'=>self::decimal($discount),
            'tax'=>self::decimal($tax),'shipping'=>self::decimal($shipping),'total'=>self::decimal($total),'lines'=>$lines,'attribution'=>$observations];
    }

    private static function minor(mixed $value):int
    {
        if (!is_string($value)||preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$value)!==1) throw new DomainException('Money must be an exact decimal string.');
        return (int)str_replace('.','',$value);
    }
    private static function decimal(int $value):string{return intdiv($value,100).'.'.str_pad((string)($value%100),2,'0',STR_PAD_LEFT);}
    private static function label(mixed $value,int $max):string
    {
        if (!is_string($value)||trim($value)===''||mb_strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value)) throw new DomainException('Invalid order label.');
        return trim($value);
    }
    private static function reference(mixed $value):?string
    {
        if ($value===null) return null;
        if (!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/D',$value)!==1) throw new DomainException('Invalid product reference.');
        return $value;
    }
}
