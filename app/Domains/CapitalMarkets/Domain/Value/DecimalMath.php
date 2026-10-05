<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use DivisionByZeroError;
use InvalidArgumentException;

final class DecimalMath
{
    public static function add(Decimal $left,Decimal $right):Decimal
    {
        [$ls,$ld,$lscale]=self::parts($left);
        [$rs,$rd,$rscale]=self::parts($right);
        $scale=max($lscale,$rscale);
        $ld.=str_repeat('0',$scale-$lscale);
        $rd.=str_repeat('0',$scale-$rscale);

        if($ls===$rs){
            return self::compose($ls,self::addAbs($ld,$rd),$scale);
        }

        $cmp=self::compareAbs($ld,$rd);
        if($cmp===0)return Decimal::fromString('0');
        return $cmp>0
            ?self::compose($ls,self::subAbs($ld,$rd),$scale)
            :self::compose($rs,self::subAbs($rd,$ld),$scale);
    }

    public static function subtract(Decimal $left,Decimal $right):Decimal
    {
        $negative=$right->isNegative()
            ?Decimal::fromString(ltrim($right->value(),'-'))
            :Decimal::fromString('-'.$right->value());
        return self::add($left,$negative);
    }

    public static function multiplyByInt(Decimal $value,int $factor):Decimal
    {
        if(abs($factor)>1_000_000_000)throw new InvalidArgumentException('Decimal integer factor is too large.');
        if($factor===0||$value->isZero())return Decimal::fromString('0');
        [$sign,$digits,$scale]=self::parts($value);
        if($factor<0){$sign*=-1;$factor=abs($factor);}
        $carry=0;$out='';
        for($i=strlen($digits)-1;$i>=0;$i--){
            $product=((int)$digits[$i])*$factor+$carry;
            $out=(string)($product%10).$out;
            $carry=intdiv($product,10);
        }
        while($carry>0){
            $out=(string)($carry%10).$out;
            $carry=intdiv($carry,10);
        }
        return self::compose($sign,$out,$scale);
    }

    public static function divide(Decimal $numerator,Decimal $denominator,int $scale=12):Decimal
    {
        if($scale<0||$scale>30)throw new InvalidArgumentException('Decimal division scale must be between 0 and 30.');
        if($denominator->isZero())throw new DivisionByZeroError('Decimal division by zero.');
        if($numerator->isZero())return Decimal::fromString('0');
        [$ns,$nd,$nscale]=self::parts($numerator);
        [$ds,$dd,$dscale]=self::parts($denominator);
        $nd.=str_repeat('0',$dscale+$scale);
        $dd.=str_repeat('0',$nscale);
        $quotient=self::divAbs($nd,$dd);
        return self::compose($ns*$ds,$quotient,$scale);
    }

    /** @return array{int,string,int} */
    private static function parts(Decimal $value):array
    {
        $raw=$value->value();
        $sign=str_starts_with($raw,'-')?-1:1;
        $raw=ltrim($raw,'-');
        [$integer,$fraction]=array_pad(explode('.',$raw,2),2,'');
        $digits=ltrim($integer.$fraction,'0');
        return [$sign,$digits===''?'0':$digits,strlen($fraction)];
    }

    private static function compose(int $sign,string $digits,int $scale):Decimal
    {
        $digits=ltrim($digits,'0');
        if($digits==='')return Decimal::fromString('0');
        if($scale>0){
            $digits=str_pad($digits,$scale+1,'0',STR_PAD_LEFT);
            $value=substr($digits,0,-$scale).'.'.substr($digits,-$scale);
        }else{
            $value=$digits;
        }
        if($sign<0)$value='-'.$value;
        return Decimal::fromString($value);
    }

    private static function compareAbs(string $a,string $b):int
    {
        $a=ltrim($a,'0');$b=ltrim($b,'0');
        $a=$a===''?'0':$a;$b=$b===''?'0':$b;
        $length=strlen($a)<=>strlen($b);
        return $length!==0?$length:(strcmp($a,$b)<=>0);
    }

    private static function addAbs(string $a,string $b):string
    {
        $i=strlen($a)-1;$j=strlen($b)-1;$carry=0;$out='';
        while($i>=0||$j>=0||$carry>0){
            $sum=($i>=0?(int)$a[$i--]:0)+($j>=0?(int)$b[$j--]:0)+$carry;
            $out=(string)($sum%10).$out;
            $carry=intdiv($sum,10);
        }
        return ltrim($out,'0')?:'0';
    }

    private static function subAbs(string $a,string $b):string
    {
        $i=strlen($a)-1;$j=strlen($b)-1;$borrow=0;$out='';
        while($i>=0){
            $digit=(int)$a[$i--]-$borrow-($j>=0?(int)$b[$j--]:0);
            if($digit<0){$digit+=10;$borrow=1;}else{$borrow=0;}
            $out=(string)$digit.$out;
        }
        return ltrim($out,'0')?:'0';
    }

    private static function divAbs(string $numerator,string $denominator):string
    {
        $numerator=ltrim($numerator,'0')?:'0';
        $denominator=ltrim($denominator,'0')?:'0';
        if($denominator==='0')throw new DivisionByZeroError('Decimal division by zero.');
        if(self::compareAbs($numerator,$denominator)<0)return '0';
        $quotient='';$remainder='0';
        foreach(str_split($numerator) as $digit){
            $remainder=ltrim(($remainder==='0'?'':$remainder).$digit,'0')?:'0';
            $q=0;
            for($candidate=9;$candidate>=1;$candidate--){
                $multiple=self::mulAbsByDigit($denominator,$candidate);
                if(self::compareAbs($remainder,$multiple)>=0){
                    $q=$candidate;
                    $remainder=self::subAbs($remainder,$multiple);
                    break;
                }
            }
            $quotient.=(string)$q;
        }
        return ltrim($quotient,'0')?:'0';
    }

    private static function mulAbsByDigit(string $value,int $digit):string
    {
        if($digit===0)return '0';
        $carry=0;$out='';
        for($i=strlen($value)-1;$i>=0;$i--){
            $product=((int)$value[$i])*$digit+$carry;
            $out=(string)($product%10).$out;
            $carry=intdiv($product,10);
        }
        if($carry>0)$out=(string)$carry.$out;
        return ltrim($out,'0')?:'0';
    }
}
