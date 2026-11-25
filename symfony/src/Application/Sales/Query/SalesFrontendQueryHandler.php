<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use DomainException;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Operations\Contract\OperationsReadModelInterface;

final readonly class SalesFrontendQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SalesWorkspaceOperationalReadModelInterface $workspace,
        private OperationsReadModelInterface $operations,
        private \App\Security\SalesRequestScope $scope,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(SalesFrontendQuery $query): array
    {
        $organizationId = $query->organizationId->value();

        return match ($query->operation) {
            SalesFrontendQuery::SEARCH => $this->search(
                $organizationId,
                trim((string) ($query->input['q'] ?? '')),
                max(1, min(12, (int) ($query->input['limit'] ?? 6))),
            ),
            SalesFrontendQuery::INTELLIGENCE => $this->intelligence($organizationId, $query->input),
            default => throw new DomainException('Unsupported Sales frontend query.'),
        };
    }

    private function search(string $org,string $query,int $limit):array
    {
        $owner=$this->scope->searchOwner($org);
        $result=$this->workspace->search($org,$query,$limit,$owner);
        if ($this->scope->searchOwner($org)!==$owner) throw new DomainException('Sales search scope changed.');
        $items=[];$groups=['deals'=>0,'leads'=>0,'people'=>0];
        foreach ($result['items'] as $item) {
            $type=$item['type']??'';
            if ($type==='PERSON'&&$owner===null) {$items[]=$item;$groups['people']++;continue;}
            if (!in_array($type,['LEAD','DEAL'],true)||!$this->scope->entity($org,strtolower($type),(int)($item['id']??0))) continue;
            $items[]=$item;$groups[$type==='LEAD'?'leads':'deals']++;
        }
        $result['items']=$items;$result['groups']=$groups;return $result;
    }

    /** @param array<string,mixed> $input */
    private function intelligence(string $organizationId, array $input): array
    {
        $dealId = (int) ($input['deal_id'] ?? 0);
        if ($dealId <= 0) {
            throw new DomainException('Invalid opportunity id.');
        }

        if (!$this->scope->entity($organizationId,'deal',$dealId)) throw new DomainException('Opportunity not found.');
        $result=$this->operations->dealIntelligence($organizationId,$dealId);
        if (!$this->scope->entity($organizationId,'deal',$dealId)) throw new DomainException('Opportunity not found.');
        return $result;
    }
}
