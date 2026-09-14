<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface InboundCaseResolverInterface
{
    public function resolvePropertyId(mixed $value): ?int;

    /** @return array{person_id:int|null,client_case_id:int|null} */
    public function resolvePersonAndCase(array $input): array;

    public function attachRequest(int $caseId, int $requestId): void;
}
