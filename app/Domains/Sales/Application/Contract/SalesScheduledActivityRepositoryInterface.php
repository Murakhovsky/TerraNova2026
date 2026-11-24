<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesScheduledActivityRepositoryInterface
{
    public function lockTarget(string $org,string $kind,int $target):?array;
    public function read(string $org,string $kind,int $target,int $activity):?array;
    public function transition(string $org,string $kind,int $target,int $activity,string $operation,?string $due,?string $reason,int $actor):void;
    public function overdue(string $org,string $now,int $limit,bool $unannounced=false):array;
    public function owner(string $org,int $user):?array;
}
