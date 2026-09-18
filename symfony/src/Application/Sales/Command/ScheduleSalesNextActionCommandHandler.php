<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ScheduleSalesNextActionCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(ScheduleSalesNextActionCommand $command): SalesMutationResult
    {
        $result = $this->writes->forOrganization($command->organizationId->value())->scheduleNextAction(
            $command->opportunityId,
            $command->title,
            $command->body,
            $command->dueAt,
            $command->actorId,
            $command->correlationId,
            $command->idempotencyKey,
        );

        if (!$result->successful) {
            $error = $result->error ?? 'next_action_failed';
            if (str_contains(strtolower($error), 'deal was not found')) {
                return SalesMutationResult::failure('not_found', 'Opportunity not found.');
            }

            return SalesMutationResult::failure(self::stableCode($error), $error);
        }

        $duplicate = ($result->data['duplicate'] ?? false) === true;

        return SalesMutationResult::success(
            $duplicate ? 'next_action_duplicate' : 'next_action_created',
            [
                'activity_id' => $result->externalId,
                ...$result->data,
            ],
        );
    }

    private static function stableCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value !== '' ? $value : 'next_action_failed';
    }
}
