<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
interface MarketingPaidLaunchPlanStore
{
    public function listForCampaign(string $org,string $campaign,int $limit=50):array;
    public function byKey(string $org,string $key):?array;
    public function get(string $org,string $id):?array;
    public function create(string $org,string $actor,string $key,string $requestHash,array $plan):array;
}
