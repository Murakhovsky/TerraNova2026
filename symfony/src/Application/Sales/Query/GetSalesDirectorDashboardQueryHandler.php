<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use DateTimeImmutable;
use DateTimeZone;
use App\Security\SalesRequestScope;
use Domains\Sales\Application\Service\SalesDirectorCockpitService;
use Domains\Sales\Application\Service\SalesCommercialAnalyticsService;
use Domains\Sales\Application\Contract\SalesWorkspaceSummaryReadPort;
use Kernel\Application\Query\QueryHandlerInterface;
use DomainException;

final readonly class GetSalesDirectorDashboardQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesDirectorCockpitService $director,private SalesRequestScope $scope,
        private SalesCommercialAnalyticsService $analytics,private SalesWorkspaceSummaryReadPort $summary) {}

    public function __invoke(GetSalesDirectorDashboardQuery $query):array
    {
        $org=$query->organizationId->value();$actor=$this->scope->actor($org);
        if ($this->scope->searchOwner($org)!==null) throw new DomainException('Sales director access denied.');
        $historyDays=max(1,min(366,$query->historyDays));
        $forecastDays=max(1,min(366,$query->forecastDays));
        $pipeline=$query->pipelineId!==null&&trim($query->pipelineId)!==''?trim($query->pipelineId):null;
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $today=$now->setTime(0,0);
        $historyTo=$today->modify('+1 day');
        $history=['scope'=>'tenant','from'=>$historyTo->modify('-'.$historyDays.' days')->format('Y-m-d'),
            'to'=>$historyTo->format('Y-m-d')];
        $future=['scope'=>'tenant','from'=>$today->format('Y-m-d'),'to'=>$today->modify('+'.$forecastDays.' days')->format('Y-m-d')];
        $filter=$pipeline===null?[]:['pipeline_id'=>$pipeline];
        $result=$this->director->overview($org,$now,$historyDays,$forecastDays,$pipeline);
        $result['canonical']=[
            'summary'=>$this->summary->summary($org,null,$pipeline),
            'outcomes'=>$this->analytics->read($org,$actor,'outcomes',$history+$filter),
            'forecast'=>$this->analytics->read($org,$actor,'forecast',$future+$filter),
            'conversion'=>$this->analytics->read($org,$actor,'conversion',$history),
            'stages'=>$this->analytics->read($org,$actor,'stages',$history+$filter),
        ];
        if ($this->scope->searchOwner($org)!==null) throw new DomainException('Sales director access changed.');
        return $result;
    }
}
