<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

interface MarketingPaidReadRepository
{
    /** @param array<string,mixed> $verified @return array<string,mixed> */
    public function registerAccount(string $organizationId,string $credentialRef,array $verified):array;
    /** @return list<array<string,mixed>> */
    public function accounts(string $organizationId,int $limit=100):array;
    /** @return array<string,mixed>|null */
    public function account(string $organizationId,string $id):?array;
    /** @param array<string,list<array<string,mixed>>> $hierarchy @return array<string,int> */
    public function importHierarchy(string $organizationId,string $accountId,array $hierarchy):array;
    /** @param list<array<string,mixed>> $rows @return int */
    public function importInsights(string $organizationId,string $accountId,string $grain,string $from,string $to,array $rows):int;
    /** @return list<array<string,mixed>> */
    public function insights(string $organizationId,string $accountId,string $grain,string $from,string $to):array;
    /** @return list<array<string,mixed>> */
    public function campaigns(string $organizationId,string $accountId,int $limit=100):array;
}
