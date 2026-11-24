<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Sales\Command\CreateSalesLeadCommand;
use App\Application\Sales\Command\SalesMutationResult;
use DomainException;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

/** Authenticated intake only; provider identity and webhook admission are separate. */
final readonly class SalesLeadCreateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(
        private CommandBusInterface $commands,
        private SalesProposalLeadReadModelInterface $sales,
    ) {}

    public function binding(): string { return 'command:sales.lead.create'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $user = $actor->userId()->value();
        if (!ctype_digit($user) || (int)$user < 1 || (string)(int)$user !== $user
            || trim($key) === '' || strlen($key) > 191) {
            throw new DomainException('Invalid Sales lead creation authority.');
        }
        $allowed = ['full_name','phone','email','role','deal_type','message','request_intent','property_id'];
        if (array_diff(array_keys($input),$allowed) !== []
            || !is_string($input['full_name'] ?? null) || trim($input['full_name']) === ''
            || (trim((string)($input['phone'] ?? '')) === '' && trim((string)($input['email'] ?? '')) === '')) {
            throw new DomainException('Lead requires a name and at least one contact.');
        }
        if (isset($input['email']) && filter_var(trim($input['email']),FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Invalid lead email.');
        }
        $org = $actor->organizationId()->value();
        $result = $this->commands->dispatch(new CreateSalesLeadCommand(
            $actor->organizationId(), (int)$user, $correlationId, $key, [...$input,'source'=>'manager-api'],
        ));
        if (!$result instanceof SalesMutationResult || !$result->ok
            || !in_array($result->code,['created','duplicate'],true)) {
            throw new DomainException('Sales lead creation rejected.');
        }
        $id = $result->data['lead_id'] ?? null;
        $row = is_int($id) && $id > 0 ? $this->sales->lead($org,$id) : null;
        if ($row === null || ($row['organization_id'] ?? null) !== $org
            || !in_array($row['id'] ?? null,[$id,(string)$id],true)) {
            throw new DomainException('Sales lead creation has no verified native result.');
        }
        if ($result->code === 'created'
            && (($row['status'] ?? null) !== 'new'
                || ($row['full_name'] ?? null) !== trim($input['full_name'])
                || !in_array($row['assigned_user_id'] ?? null,[(int)$user,$user],true)
                || ($row['phone'] ?? null) !== (trim((string)($input['phone'] ?? '')) ?: null)
                || ($row['email'] ?? null) !== (trim((string)($input['email'] ?? '')) ?: null))) {
            throw new DomainException('Created Sales lead differs from requested native state.');
        }
        return ['lead_id'=>$id,'replayed'=>$result->code==='duplicate'];
    }
}
