<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Domain;

use DomainException;

final class AttributeRules
{
    public static function code(string $code):string
    {
        if(!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$code))throw new DomainException('Invalid attribute code.');
        return $code;
    }
    public static function definition(array $in):array
    {
        $code=self::code((string)($in['code']??''));
        $name=trim((string)($in['name']??''));
        $type=$in['value_type']??null;$unit=$in['unit']??null;$choices=$in['choices']??[];
        if($name===''||mb_strlen($name)>190||!in_array($type,['text','decimal','boolean','enum'],true)
            ||($unit!==null&&($type!=='decimal'||!is_string($unit)||!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,15}$/D',$unit)))
            ||!is_array($choices)||!array_is_list($choices)||count($choices)>50||($type==='enum'&&$choices===[])||($type!=='enum'&&$choices!==[]))
            throw new DomainException('Invalid attribute definition.');
        $normal=[];
        foreach($choices as $choice){
            if(!is_string($choice)||!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D',$choice)||in_array($choice,$normal,true))throw new DomainException('Invalid or duplicate enum choice.');
            $normal[]=$choice;
        }
        sort($normal,SORT_STRING);
        return ['code'=>$code,'name'=>$name,'value_type'=>$type,'unit'=>$unit,'choices'=>$normal];
    }
    public static function value(array $definition,mixed $value):mixed
    {
        if($value===null)return null;
        switch($definition['value_type']){
            case 'boolean':
                if(is_bool($value))return $value;
                break;
            case 'text':
                if(is_string($value)&&trim($value)!==''&&mb_strlen($value)<=500)return trim($value);
                break;
            case 'enum':
                if(is_string($value)&&in_array($value,$definition['choices'],true))return $value;
                break;
            case 'decimal':
                if(is_string($value)&&preg_match('/^-?(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D',$value)){
                    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
                    $normalized=$whole.'.'.str_pad($fraction,6,'0');
                    return $normalized==='-0.000000'?'0.000000':$normalized;
                }
        }
        throw new DomainException('Value does not match attribute type: '.$definition['code']);
    }
    public static function changes(array $rows):array
    {
        if(!array_is_list($rows)||count($rows)<1||count($rows)>50)throw new DomainException('Attribute patch requires 1..50 values.');
        $result=[];
        foreach($rows as $row){
            if(!is_array($row)||count($row)!==2||!is_string($row['code']??null)||!array_key_exists('value',$row))throw new DomainException('Invalid attribute patch.');
            $code=self::code($row['code']);
            if(array_key_exists($code,$result))throw new DomainException('Duplicate attribute.');
            $result[$code]=$row['value'];
        }
        ksort($result,SORT_STRING);return $result;
    }
    public static function matrix(array $axes,string $prefix):array
    {
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/D',$prefix)||!array_is_list($axes)||count($axes)<1||count($axes)>4)
            throw new DomainException('Matrix needs 1..4 axes and SKU prefix.');
        $map=[];
        foreach($axes as $axis){
            if(!is_array($axis)||count($axis)!==2||!is_string($axis['code']??null)||!is_array($axis['choices']??null)||!array_is_list($axis['choices'])||count($axis['choices'])<1||count($axis['choices'])>50)
                throw new DomainException('Invalid matrix axis.');
            $code=self::code($axis['code']);
            if(isset($map[$code]))throw new DomainException('Duplicate matrix axis.');
            $values=[];
            foreach($axis['choices'] as $v){
                if(!is_string($v)||!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D',$v)||in_array($v,$values,true))throw new DomainException('Invalid matrix choice.');
                $values[]=$v;
            }
            sort($values,SORT_STRING);$map[$code]=$values;
        }
        ksort($map,SORT_STRING);
        $combinations=[[]];
        foreach($map as $code=>$values){
            if(count($combinations)*count($values)>50)throw new DomainException('Matrix limited to 50 combinations.');
            $next=[];foreach($combinations as $combination)foreach($values as $v)$next[]=$combination+[$code=>$v];
            $combinations=$next;
        }
        return array_map(static function(array $options)use($prefix):array{
            $hash=hash('sha256',json_encode($options,JSON_THROW_ON_ERROR));
            return ['sku'=>ProductRules::sku($prefix.'-'.substr($hash,0,16)),'options'=>$options,'combination_hash'=>$hash];
        },$combinations);
    }
}
