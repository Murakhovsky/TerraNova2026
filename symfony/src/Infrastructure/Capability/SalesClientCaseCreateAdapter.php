<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommand;
use App\Application\Sales\Command\SalesMutationResult;
use DomainException;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

/** Convert one native lead once; ownership changes are separate operations. */
final readonly class SalesClientCaseCreateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(
        private CommandBusInterface $commands,
        private SalesWorkspaceReadModelInterface $sales,
    ) {}

    public function binding(): string { return 'command:sales.client_case.create'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $leadId = $input['lead_id'] ?? null;
        $user = $actor->userId()->value();
        if (count($input) !== 1 || !is_int($leadId) || $leadId < 1
            || !ctype_digit($user) || (int)$user < 1 || (string)(int)$user !== $user
            || trim($key) === '' || strlen($key) > 191) {
            throw new DomainException('Invalid Sales conversion input.');
        }
        $org = $actor->organizationId()->value();
        $before = $this->sales->lead($org, $leadId);
        if (!$this->owns($before, $org, $leadId)) {
            throw new DomainException('Lead is not available in this organization.');
        }
        $receipt = $this->commands->dispatch(new ConvertSalesLeadToOpportunityCommand(
            $actor->organizationId(), (int)$user, $leadId, $correlationId, [],
        ));
        if (!$receipt instanceof SalesMutationResult || !$receipt->ok
            || !in_array($receipt->code, ['created', 'already_attached'], true)) {
            throw new DomainException('Sales conversion was not accepted.');
        }
        $caseId = $receipt->data['case_id'] ?? null;
        if (!is_int($caseId) || $caseId < 1) {
            throw new DomainException('Invalid native Sales conversion receipt.');
        }
        $after = $this->sales->lead($org, $leadId);
        $case = $this->sales->deal($org, $caseId);
        if (!$this->owns($after, $org, $leadId) || !$this->owns($case, $org, $caseId)
            || !$this->sameId($after['client_case_id'] ?? null, $caseId)
            || ($receipt->code === 'created' && ($after['status'] ?? null) !== 'qualified')
            || (!empty($before['client_case_id']) && !$this->sameId($before['client_case_id'], $caseId))
            || (!empty($before['client_case_id']) && $receipt->code !== 'already_attached')) {
            throw new DomainException('Sales conversion could not be verified by native readback.');
        }
        return ['lead_id' => $leadId, 'case_id' => $caseId, 'replayed' => $receipt->code === 'already_attached'];
    }

    private function owns(?array $row, string $org, int $id): bool
    {
        return $row !== null && ($row['organization_id'] ?? null) === $org
            && $this->sameId($row['id'] ?? null, $id);
    }

    private function sameId(mixed $value, int $id): bool
    {
        return $value === $id || $value === (string)$id;
    }
}
