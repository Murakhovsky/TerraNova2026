<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceSummaryReadPort;
use Kernel\Tenant\Model\TenantContext;
use DomainException;

final readonly class SalesWorkspaceSummaryAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesWorkspaceScopeInterface $scope,private SalesWorkspaceSummaryReadPort $summary){}
    public function binding():string{return 'query:sales.workspace.summary.read';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $org=$actor->organizationId()->value();$raw=$actor->userId()->value();
        $scope=$input['scope']??'self';$owner=$input['owner_id']??null;$pipeline=$input['pipeline_id']??null;
        if(!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw||array_diff(array_keys($input),['scope','owner_id','pipeline_id'])!==[]||!in_array($scope,['self','owner','tenant'],true)
            ||(array_key_exists('owner_id',$input)&&(!is_int($owner)||$owner<1))
            ||(array_key_exists('pipeline_id',$input)&&(!is_string($pipeline)||preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,79}$/D',$pipeline)!==1)))throw new DomainException('Invalid Sales summary filters.');
        return $this->summary->summary($org,$this->scope->resolve($org,(int)$raw,$scope,$owner),$pipeline);
    }
}
