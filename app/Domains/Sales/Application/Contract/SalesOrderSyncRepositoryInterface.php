<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesOrderSyncRepositoryInterface
{
    public function open(string $org,string $run,string $hash,int $actor,string $channel,string $connection,string $mode,string $cutoff):array;
    public function run(string $org,string $run,bool $lock=false):?array;
    public function task(string $org,string $job,bool $lock=false):?array;
    public function register(string $org,string $run,string $key,string $job,string $kind,array $input):bool;
    public function advance(string $org,string $run,?string $expected,?string $next,?string $history,?string $review):void;
    public function finish(string $org,string $job,string $state,string $outcome,?string $reason,?string $order):void;
    public function summary(string $org,string $run):array;
    public function tasks(string $org,string $run,?string $after):array;
}
