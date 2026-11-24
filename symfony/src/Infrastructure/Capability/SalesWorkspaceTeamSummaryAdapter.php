<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Service\SalesTeamSummaryService;
use Kernel\Tenant\Model\TenantContext;
use DomainException;

final readonly class SalesWorkspaceTeamSummaryAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesTeamSummaryService $summary) {}
    public function binding():string{return 'query:sales.workspace.team.summary.read';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $raw=$actor->userId()->value();
        if (!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw) throw new DomainException('Invalid Sales actor.');
        return $this->summary->read($actor->organizationId()->value(),(int)$raw,$input);
    }
}
