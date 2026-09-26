<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Feed;

use DateTimeImmutable;
use Domains\Growth\Application\DTO\ExternalJsonSignalEntry;
use InvalidArgumentException;
use Throwable;

final class CredentialedJsonSignalParser
{
    /** @return list<ExternalJsonSignalEntry> */
    public function parse(string $json,int $limit):array
    {
        if($limit<1||$limit>200)throw new InvalidArgumentException('Credentialed JSON parser limit must be between 1 and 200.');
        if(trim($json)==='')throw new InvalidArgumentException('Credentialed JSON response body is empty.');

        try{
            $payload=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        }catch(Throwable){
            throw new InvalidArgumentException('Credentialed JSON response is not valid JSON.');
        }
        if(!is_array($payload)||array_is_list($payload)){
            throw new InvalidArgumentException('Credentialed JSON response must be an object.');
        }
        $items=$payload['items']??null;
        if(!is_array($items)||!array_is_list($items)){
            throw new InvalidArgumentException('Credentialed JSON response must contain an items list.');
        }
        if(count($items)>500){
            throw new InvalidArgumentException('Credentialed JSON response contains too many items.');
        }

        $entries=[];
        $seen=[];
        foreach($items as $row){
            if(!is_array($row)||array_is_list($row))continue;
            $externalId=$this->string($row['id']??null,1000);
            $sourceReference=$this->string($row['source_reference']??null,2000);
            $occurredRaw=$this->string($row['occurred_at']??null,100);
            $facts=$row['facts']??null;
            if($externalId===''||$sourceReference===''||$occurredRaw===''||!is_array($facts)||array_is_list($facts)||$facts===[])continue;

            try{
                $occurredAt=new DateTimeImmutable($occurredRaw);
                $entry=new ExternalJsonSignalEntry($externalId,$sourceReference,$facts,$occurredAt);
            }catch(Throwable){
                continue;
            }

            $dedupe=hash('sha256',$externalId);
            if(isset($seen[$dedupe]))continue;
            $seen[$dedupe]=true;
            $entries[]=$entry;
        }

        usort($entries,static function(ExternalJsonSignalEntry $a,ExternalJsonSignalEntry $b):int{
            $cmp=$b->occurredAt<=>$a->occurredAt;
            return $cmp!==0?$cmp:strcmp($a->externalId,$b->externalId);
        });
        return array_slice($entries,0,$limit);
    }

    private function string(mixed $value,int $limit):string
    {
        if(!is_string($value))return '';
        $value=trim($value);
        return mb_strlen($value)<=$limit?$value:'';
    }
}
