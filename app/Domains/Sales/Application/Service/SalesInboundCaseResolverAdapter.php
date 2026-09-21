<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DomainException;
use Domains\Sales\Application\Contract\InboundCaseResolverInterface;

final readonly class SalesInboundCaseResolverAdapter implements InboundCaseResolverInterface
{
    public function __construct(private SalesInboundService $inbound) {}
    public function resolvePropertyId(mixed $value): ?int { return $this->inbound->resolvePropertyId($value); }
    public function resolvePersonAndCase(array $input): array
    {
        $result=$this->inbound->ensureCase($input);
        if(!$result->ok) throw new DomainException('Public lead case resolution failed: '.$result->code);
        return [
            'person_id'=>isset($result->data['person_id'])?(int)$result->data['person_id']:null,
            'client_case_id'=>isset($result->data['client_case_id'])?(int)$result->data['client_case_id']:null,
        ];
    }
    public function attachRequest(int $caseId,int $requestId): void { $this->inbound->registerRequest($caseId,$requestId); }
}
