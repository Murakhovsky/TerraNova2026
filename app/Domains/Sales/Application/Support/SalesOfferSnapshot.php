<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Support;

use DomainException;

final class SalesOfferSnapshot
{
    public static function fromNative(array $row,string $org,string $id):array
    {
        if(($row['organization_id']??null)!==$org||($row['offer_id']??null)!==$id||(int)$row['deal_id']<1)throw new DomainException('Offer identity mismatch.');
        $lines=json_decode((string)$row['lines_json'],true,flags:JSON_THROW_ON_ERROR);
        if(!is_array($lines)||!array_is_list($lines)||$lines===[])throw new DomainException('Native offer terms invalid.');
        $terms=['title'=>(string)$row['title'],'currency'=>(string)$row['currency'],'total'=>(string)$row['total'],'valid_until'=>(string)$row['valid_until'],'lines'=>$lines];
        // MySQL JSON normalizes object key order, so hash verification canonicalizes recursively.
        if(!hash_equals((string)$row['terms_hash'],self::digest($terms)))throw new DomainException('Native offer terms integrity failed.');
        return ['offer_id'=>$id,'deal_id'=>(int)$row['deal_id'],'status'=>(string)$row['status'],'revision'=>(int)$row['revision'],
            'terms_version'=>(int)$row['terms_version'],...$terms,'direction'=>(string)$row['direction'],'terms_hash'=>$row['terms_hash'],
            'accepted_at'=>$row['accepted_at']===null?null:(string)$row['accepted_at']];
    }
    public static function digest(array $value):string
    {
        return hash('sha256',json_encode(self::canonical($value),JSON_THROW_ON_ERROR));
    }
    private static function canonical(array $value):array
    {
        if(!array_is_list($value))ksort($value);
        foreach($value as &$child)if(is_array($child))$child=self::canonical($child);
        unset($child);return $value;
    }
}
