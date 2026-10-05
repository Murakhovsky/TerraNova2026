<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use DivisionByZeroError;
use InvalidArgumentException;

final class DecimalMath
{
    private function __construct(){}

    public static function add(Decimal $left,Decimal $right):Decimal
    {
        [$ls,$ld,$lscale]=self::parts($left);
        [$rs,$rd,$rscale]=self::parts($right);
        $scale=max($lscale,$rscale);
        $ld.=str_repeat('0',$scale-$lscale);
        $rd.=str_repeat('0',$scale-$rscale);

        if($ls===$rs){
            return self::fromParts($ls,self::addUnsigned($ld,$rd),$scale);
        }

        $cmp=self::compareUnsigned($ld,$rd);
        if($cmp===0)return Decimal::fromString('0');
        if($cmp>0)return self::fromParts($ls,self::subtractUnsigned($ld,$rd),$scale);
        return self::fromParts($rs,self::subtractUnsigned($rd,$ld),$scale);
    }

    public static function subtract(Decimal $left,Decimal $right):Decimal
    {
        return self::add($left,self::negate($right));
    }

    public static function multiply(Decimal $left,Decimal $right):Decimal
    {
        [$ls,$ld,$lscale]=self::parts($left);
        [$rs,$rd,$rscale]=self::parts($right);
        if($ld==='0'||$rd==='0')return Decimal::fromString('0');
        return self::fromParts($ls*$rs,self::multiplyUnsigned($ld,$rd),$lscale+$rscale);
    }

    public static function multiplyInteger(Decimal $value,int $factor):Decimal
    {
        return self::multiply($value,Decimal::fromString((string)$factor));
    }

    public static function divide(Decimal $numerator,Decimal $denominator,int $scale=12):Decimal
    {
        if($scale<0||$scale>30)throw new InvalidArgumentException('Decimal division scale must be between 0 and 30.');
        [$ns,$nd,$nscale]=self::parts($numerator);
        [$ds,$dd,$dscale]=self::parts($denominator);
        if($dd==='0')throw new DivisionByZeroError('Decimal division by zero.');
        if($nd==='0')return Decimal::fromString('0');

        $scaledNumerator=$nd.str_repeat('0',$scale+$dscale);
        $scaledDenominator=$dd.str_repeat('0',$nscale);
        $quotient=self::divideUnsigned($scaledNumerator,$scaledDenominator);
        return self::fromParts($ns*$ds,$quotient,$scale);
    }

    public static function midpoint(Decimal $left,Decimal $right,int $scale=12):Decimal
    {
        return self::divide(self::add($left,$right),Decimal::fromString('2'),$scale);
    }

    public static function basisPoints(Decimal $difference,Decimal $reference,int $scale=6):Decimal
    {
        if($reference->isZero())throw new DivisionByZeroError('Basis-point reference cannot be zero.');
        return self::divide(self::multiplyInteger($difference,10000),$reference,$scale);
    }

    public static function abs(Decimal $value):Decimal
    {
        return $value->isNegative()?self::negate($value):$value;
    }

    public static function negate(Decimal $value):Decimal
    {
        if($value->isZero())return $value;
        return Decimal::fromString($value->isNegative()?substr($value->value(),1):'-'.$value->value());
    }

    /** @return array{0:int,1:string,2:int} */
    private static function parts(Decimal $value):array
    {
        $raw=$value->value();
        $sign=str_starts_with($raw,'-')?-1:1;
        if($sign<0)$raw=substr($raw,1);
        [$integer,$fraction]=array_pad(explode('.',$raw,2),2,'');
        return [$sign,self::trimUnsigned($integer.$fraction),strlen($fraction)];
    }

    private static function fromParts(int $sign,string $digits,int $scale):Decimal
    {
        $digits=self::trimUnsigned($digits);
        if($digits==='0')return Decimal::fromString('0');
        if($scale>0){
            $digits=str_pad($digits,$scale+1,'0',STR_PAD_LEFT);
            $split=strlen($digits)-$scale;
            $raw=substr($digits,0,$split).'.'.substr($digits,$split);
        }else{
            $raw=$digits;
        }
        return Decimal::fromString(($sign<0?'-':'').$raw);
    }

    private static function trimUnsigned(string $digits):string
    {
        $digits=ltrim($digits,'0');
        return $digits===''?'0':$digits;
    }

    private static function compareUnsigned(string $left,string $right):int
    {
        $left=self::trimUnsigned($left);
        $right=self::trimUnsigned($right);
        $length=strlen($left)<=>strlen($right);
        return $length!==0?$length:(strcmp($left,$right)<=>0);
    }

    private static function addUnsigned(string $left,string $right):string
    {
        $i=strlen($left)-1;$j=strlen($right)-1;$carry=0;$out='';
        while($i>=0||$j>=0||$carry>0){
            $sum=$carry+($i>=0?ord($left[$i])-48:0)+($j>=0?ord($right[$j])-48:0);
            $out=chr(48+($sum%10)).$out;
            $carry=intdiv($sum,10);$i--;$j--;
        }
        return self::trimUnsigned($out);
    }

    private static function subtractUnsigned(string $left,string $right):string
    {
        if(self::compareUnsigned($left,$right)<0)throw new InvalidArgumentException('Unsigned subtraction requires left >= right.');
        $i=strlen($left)-1;$j=strlen($right)-1;$borrow=0;$out='';
        while($i>=0){
            $digit=(ord($left[$i])-48)-$borrow-($j>=0?ord($right[$j])-48:0);
            if($digit<0){$digit+=10;$borrow=1;}else{$borrow=0;}
            $out=chr(48+$digit).$out;$i--;$j--;
        }
        return self::trimUnsigned($out);
    }

    private static function multiplyUnsigned(string $left,string $right):string
    {
        $left=self::trimUnsigned($left);$right=self::trimUnsigned($right);
        if($left==='0'||$right==='0')return '0';
        $a=array_reverse(array_map(static fn(string $d):int=>ord($d)-48,str_split($left)));
        $b=array_reverse(array_map(static fn(string $d):int=>ord($d)-48,str_split($right)));
        $out=array_fill(0,count($a)+count($b),0);
        foreach($a as $i=>$ad){
            foreach($b as $j=>$bd)$out[$i+$j]+=$ad*$bd;
        }
        for($i=0;$i<count($out)-1;$i++){
            if($out[$i]>=10){
                $out[$i+1]+=intdiv($out[$i],10);
                $out[$i]%=10;
            }
        }
        while(count($out)>1&&end($out)===0)array_pop($out);
        return implode('',array_reverse($out));
    }

    private static function multiplyDigit(string $value,int $digit):string
    {
        if($digit<0||$digit>9)throw new InvalidArgumentException('Single digit multiplier must be between 0 and 9.');
        if($digit===0)return '0';
        $carry=0;$out='';
        for($i=strlen($value)-1;$i>=0;$i--){
            $product=(ord($value[$i])-48)*$digit+$carry;
            $out=chr(48+($product%10)).$out;
            $carry=intdiv($product,10);
        }
        if($carry>0)$out=(string)$carry.$out;
        return self::trimUnsigned($out);
    }

    private static function divideUnsigned(string $numerator,string $denominator):string
    {
        $numerator=self::trimUnsigned($numerator);
        $denominator=self::trimUnsigned($denominator);
        if($denominator==='0')throw new DivisionByZeroError('Unsigned division by zero.');
        if(self::compareUnsigned($numerator,$denominator)<0)return '0';

        $remainder='0';$quotient='';
        foreach(str_split($numerator) as $digit){
            $remainder=self::trimUnsigned(($remainder==='0'?'':$remainder).$digit);
            $low=0;$high=9;$best=0;
            while($low<=$high){
                $mid=intdiv($low+$high,2);
                $cmp=self::compareUnsigned(self::multiplyDigit($denominator,$mid),$remainder);
                if($cmp<=0){$best=$mid;$low=$mid+1;}else{$high=$mid-1;}
            }
            $quotient.=(string)$best;
            if($best>0)$remainder=self::subtractUnsigned($remainder,self::multiplyDigit($denominator,$best));
        }
        return self::trimUnsigned($quotient);
    }
}
