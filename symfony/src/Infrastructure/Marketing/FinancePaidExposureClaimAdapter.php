<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;
use Domains\Finance\Application\Service\ApprovedFinancePaidExposureService;
use Domains\Marketing\Application\Contract\MarketingPaidExposureClaimPort;
use Kernel\Action\Action;
final readonly class FinancePaidExposureClaimAdapter implements MarketingPaidExposureClaimPort
{
    public function __construct(private ApprovedFinancePaidExposureService $finance){}
    public function assertActive(Action $action):void{$this->finance->assertActive($action);}
    public function claim(Action $action,array $funding):array{return $this->finance->claim($action,$funding);}
}
