<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\Support\ClientCaseInput;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class AddClientCasePropertyMatch
{
    public function __construct(
        private ClientCaseReadModelInterface $readModel,
        private ClientCaseCommandRepositoryInterface $commands,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function execute(int $caseId, int $propertyId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $case = $this->readModel->case($caseId);
        if (!$case) return ClientCaseCommandResult::failure('case_not_found');
        $property = $this->commands->property($propertyId);
        if (!$property) return ClientCaseCommandResult::failure('property_not_found');

        $match = [
            'match_status' => ClientCaseInput::allowed((string) ($input['match_status'] ?? 'suggested'), ClientCaseInput::MATCH_STATUSES, 'suggested'),
            'score' => ClientCaseInput::score($input['score'] ?? null),
            'note' => ClientCaseInput::nullable((string) ($input['note'] ?? ''), 500),
        ];
        return $this->transactions->transactional(function () use ($case, $caseId, $propertyId, $property, $match, $user): ClientCaseCommandResult {
            if (!$this->commands->upsertPropertyMatch($this->organizationId, $caseId, $propertyId, $match)) {
                throw new RuntimeException('Client case disappeared while adding a property match.');
            }
            $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                'activity_type' => 'note', 'title' => 'Обʼєкт додано у підбір',
                'body' => trim($property['public_id'] . ' / ' . $property['title'] . ($match['note'] ? ' / ' . $match['note'] : '')),
                'due_at' => null, 'completed_at' => null,
            ]);
            return ClientCaseCommandResult::success('added', ['case_id' => $caseId]);
        });
    }
}
