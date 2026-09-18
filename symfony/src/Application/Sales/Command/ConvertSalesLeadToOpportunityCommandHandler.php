<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ConvertSalesLeadToOpportunityCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(ConvertSalesLeadToOpportunityCommand $command): SalesMutationResult
    {
        $result = $this->writes->forOrganization($command->organizationId->value())->convertLeadToOpportunity(
            $command->leadId,
            $command->input,
            $command->actorId,
            $command->correlationId,
        );

        return $result->ok
            ? SalesMutationResult::success($result->code, $result->data)
            : SalesMutationResult::failure($result->code, self::messageFor($result->code), $result->data);
    }

    private static function messageFor(string $code): string
    {
        return match ($code) {
            'contact_required' => 'Lead requires a name and phone or email.',
            'invalid_email' => 'Email address is invalid.',
            'invalid_owner' => 'Owner is not an active manager in this organization.',
            'invalid_status' => 'Lead status is invalid.',
            'invalid_next_contact_at' => 'next_contact_at must be a valid date-time.',
            'no_changes' => 'No supported Lead changes were supplied.',
            'request_not_found', 'not_found', 'case_not_found' => 'Sales resource was not found.',
            'activity_title_required' => 'Activity title is required.',
            'invalid_activity_type' => 'Activity type is invalid.',
            'idempotency_key_required' => 'X-Idempotency-Key is required.',
            'idempotency_conflict' => 'The idempotent operation is already in progress.',
            default => str_replace('_', ' ', $code),
        };
    }
}
