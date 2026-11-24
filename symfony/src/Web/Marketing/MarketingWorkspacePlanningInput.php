<?php
declare(strict_types=1);
namespace App\Web\Marketing;

use DomainException;

/** Maps untrusted HTML form data to Domain inputs; no tenant, approval or provider authority. */
final class MarketingWorkspacePlanningInput
{
    /** @param array<string,mixed> $form */
    public static function field(array $form,string $key,string $default=''):string
    {
        $value=$form[$key]??$default;
        if(!is_string($value))throw new DomainException('Invalid planning form field: '.$key);
        return trim($value);
    }

    /** @param array<string,mixed> $form */
    public static function revision(array $form,string $key):int
    {
        $value=self::field($form,$key);
        if(!preg_match('/^[1-9][0-9]{0,9}$/D',$value)||(int)$value>2000000000){
            throw new DomainException('Invalid planning revision.');
        }
        return (int)$value;
    }

    /** @param array<string,mixed> $form @return array{?string,?int} */
    public static function revisionPair(array $form,string $key):array
    {
        $id=self::field($form,$key);
        $revision=self::field($form,'expected_revision');
        if($id===''&&$revision==='')return [null,null];
        if($id===''||$revision==='')throw new DomainException('Incomplete revision pair.');
        return [$id,self::revision($form,'expected_revision')];
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    public static function audience(array $form):array
    {
        $markets=self::field($form,'market_countries');
        if(strlen($markets)>100)throw new DomainException('Too many market codes.');
        return ['name'=>self::field($form,'name'),'target_spec'=>[
            'market_countries'=>array_map(static fn(string $code):string=>strtoupper(trim($code)),explode(',',$markets)),
            'audience_hypothesis'=>self::field($form,'audience_hypothesis')
        ]];
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    public static function adset(array $form):array
    {
        $audience=self::pin(self::field($form,'audience_pin'),'mkau-');
        $pins=$form['creative_pins']??null;
        $placements=$form['placements']??null;
        if(!is_array($pins)||!array_is_list($pins)||count($pins)<1||count($pins)>20
            ||!is_array($placements)||!array_is_list($placements)||count($placements)<1||count($placements)>5){
            throw new DomainException('Choose 1–20 creatives and 1–5 placements.');
        }
        $creatives=[];
        foreach($pins as $pin){
            if(!is_string($pin))throw new DomainException('Invalid Creative pin.');
            [$id,$revision]=self::pin($pin,'mkcr-');
            $creatives[]=['creative_id'=>$id,'revision'=>$revision];
        }
        foreach($placements as $placement){
            if(!is_string($placement))throw new DomainException('Invalid placement.');
        }
        return ['name'=>self::field($form,'name'),'audience_id'=>$audience[0],'audience_revision'=>$audience[1],
            'creatives'=>$creatives,'placements'=>$placements,'optimization'=>self::field($form,'optimization'),
            'planned_daily_budget'=>self::field($form,'planned_daily_budget'),'currency'=>strtoupper(self::field($form,'currency'))];
    }

    /** @param array<string,mixed> $form @return array{string,int} */
    public static function analystPin(array $form,string $key,string $prefix):array
    {
        return self::pin(self::field($form,$key),$prefix);
    }

    /** @return array{string,int} */
    private static function pin(string $value,string $prefix):array
    {
        if(!preg_match('/^('.preg_quote($prefix,'/').'[a-f0-9]{24}):([1-9][0-9]{0,9})$/D',$value,$matches)
            ||(int)$matches[2]>2000000000)throw new DomainException('Invalid revision pin.');
        return [$matches[1],(int)$matches[2]];
    }
}
