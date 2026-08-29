<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\Support\ClientCaseInput;
use Domains\Sales\Model\PropertyMatchStatus;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class UpdateClientCasePropertyMatch
{
    public function __construct(
        private ClientCaseReadModelInterface $readModel,
        private ClientCaseCommandRepositoryInterface $commands,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function execute(int $matchId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $existing = $this->commands->propertyMatch($this->organizationId, $matchId);
        if (!$existing) return ClientCaseCommandResult::failure('not_found');
        $case = $this->readModel->case((int) $existing['client_case_id']);
        if (!$case) return ClientCaseCommandResult::failure('not_found');
        $match = [
            'match_status' => ClientCaseInput::allowed((string) ($input['match_status'] ?? 'suggested'), PropertyMatchStatus::values(), 'suggested'),
            'score' => ClientCaseInput::score($input['score'] ?? null),
            'note' => ClientCaseInput::nullable((string) ($input['note'] ?? ''), 500),
        ];
        $caseId = (int) $existing['client_case_id'];

        return $this->transactions->transactional(function () use ($matchId, $match, $existing, $case, $caseId, $user): ClientCaseCommandResult {
            if (!$this->commands->updatePropertyMatch($this->organizationId, $matchId, $match)) {
                throw new RuntimeException('Property match disappeared during update.');
            }
            $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                'activity_type' => 'note', 'title' => 'Підбір обʼєкта оновлено',
                'body' => trim(($existing['public_id'] ?? '') . ' / ' . ($existing['title'] ?? '') . ' / ' . $match['match_status']),
                'due_at' => null, 'completed_at' => null,
            ]);
            return ClientCaseCommandResult::success('updated', ['case_id' => $caseId]);
        });
    }
}
