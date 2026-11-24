<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Service\SalesWorkspaceScopedReadService;
use Kernel\Tenant\Model\TenantContext;
use DomainException;
final readonly class SalesWorkspaceLeadsListAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesWorkspaceScopedReadService $read){}
    public function binding():string{return 'query:sales.workspace.leads.list';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $org=$actor->organizationId()->value();$raw=$actor->userId()->value();
        if(!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw)throw new DomainException('Invalid actor.');
        return $this->read->list($org,(int)$raw,'lead',$input);
    }
}
