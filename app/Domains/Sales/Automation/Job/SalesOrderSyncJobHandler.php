<?php
declare(strict_types=1);
namespace Domains\Sales\Automation\Job;

use Domains\Sales\Application\Service\SalesOrderSyncService;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Job;

final readonly class SalesOrderSyncJobHandler implements JobHandlerInterface
{
    public function __construct(private SalesOrderSyncService $service){}
    public function supports(string $type):bool{return $type===SalesOrderSyncService::JOB;}
    public function handle(Job $job):void{$this->service->execute($job);}
}
