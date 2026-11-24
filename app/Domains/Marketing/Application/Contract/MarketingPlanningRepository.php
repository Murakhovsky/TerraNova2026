<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

interface MarketingPlanningRepository
{
    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function saveAudience(string $org,string $actor,?string $id,?int $expectedRevision,array $content,string $key):array;
    /** @return list<array<string,mixed>> */
    public function audiences(string $org):array;
    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function saveAdset(string $org,string $campaignId,int $campaignRevision,string $actor,?string $id,?int $expectedRevision,array $content,string $key):array;
    /** @return list<array<string,mixed>> */
    public function adsets(string $org,string $campaignId):array;
    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function createCreative(string $org,string $campaignId,string $actor,array $content,string $key):array;
    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function reviseCreative(string $org,string $creativeId,string $actor,int $expectedRevision,array $content,string $key):array;
    /** @return list<array<string,mixed>> */
    public function creatives(string $org,string $campaignId):array;
    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    public function submitProposal(string $org,string $campaignId,string $creativeId,int $campaignRevision,int $creativeRevision,string $actor,array $proposal,string $key):array;
    /** @return list<array<string,mixed>> */
    public function proposals(string $org,string $campaignId):array;
    /** @param array{decision:string,reason:string} $review @return array<string,mixed> */
    public function reviewProposal(string $org,string $proposalId,string $actor,int $expectedRevision,array $review,string $key):array;
}

