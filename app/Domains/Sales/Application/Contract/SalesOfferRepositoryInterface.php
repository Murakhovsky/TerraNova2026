<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesOfferRepositoryInterface
{
    public function get(string $org,string $id):?array;
    public function forDeal(string $org,int $deal,int $limit=50):array;
    public function acceptedForDeal(string $org,int $deal):?array;
    public function create(string $org,string $id,int $deal,int $actor):void;
    public function appendTerms(string $org,string $id,int $version,array $terms,string $direction,int $actor):void;
    public function advance(string $org,string $id,int $expectedRevision,int $termsVersion,string $status):void;
    public function appendHistory(string $org,string $id,int $revision,int $termsVersion,string $kind,?string $from,string $to,?string $evidence,?string $note,int $actor,string $trace):void;
    public function history(string $org,string $id,int $limit=100):array;
    public function termsHistory(string $org,string $id,int $limit=50):array;
}
