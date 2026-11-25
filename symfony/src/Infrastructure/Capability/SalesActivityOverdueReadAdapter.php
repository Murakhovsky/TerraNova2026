<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Contract\SalesScheduledActivityRepositoryInterface;
use Kernel\Tenant\Model\TenantContext;
use DomainException;
final readonly class SalesActivityOverdueReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesScheduledActivityRepositoryInterface $activities){}
    public function binding():string{return 'query:sales.activity.overdue.read';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $limit=$input['limit']??100;
        if(array_diff(array_keys($input),['limit'])!==[]||!is_int($limit)||$limit<1||$limit>200)throw new DomainException('Invalid overdue query.');
        $items=[];foreach($this->activities->overdue($actor->organizationId()->value(),date('Y-m-d H:i:s'),$limit) as $row)$items[]=[
            'target_type'=>$row['target_type'],'target_id'=>(int)$row['target_id'],'activity_id'=>(int)$row['activity_id'],'activity_type'=>$row['activity_type'],'due_at'=>$row['due_at'],'owner_id'=>$row['owner_id']===null?null:(int)$row['owner_id'],
        ];
        return ['items'=>$items];
    }
}
