<?php
declare(strict_types=1);

namespace Infrastructure\Crm\Aida;

use Common\Services\DatabaseService;
use Domains\Sales\Crm\Contract\CrmPort;
use Domains\Sales\Crm\CreateTaskCommand;
use Domains\Sales\Crm\ExternalResult;
use Throwable;

final readonly class AidaCrmAdapter implements CrmPort
{
    public function __construct(private DatabaseService $database)
    {
    }

    public function provider(): string
    {
        return 'aida';
    }

    public function createTask(CreateTaskCommand $command): ExternalResult
    {
        $ownsTransaction = false;
        try {
            $pdo = $this->database->connection();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            $existing = $this->database->fetchOne(
                "SELECT external_id FROM cos_external_references "
                . "WHERE organization_id = :organization_id AND provider = 'aida' "
                . "AND entity_type = 'task' AND cos_reference = :cos_reference LIMIT 1",
                [
                    'organization_id' => $command->organizationId,
                    'cos_reference' => 'action:' . $command->idempotencyKey,
                ],
            );
            if ($existing) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return ExternalResult::success((string) $existing['external_id'], ['duplicate' => true]);
            }

            $statement = $pdo->prepare(
                'INSERT INTO tn_client_case_activities '
                . '(client_case_id, activity_type, title, body, due_at) '
                . "VALUES (:client_case_id, 'task', :title, :body, :due_at)"
            );
            $statement->execute([
                'client_case_id' => $command->dealReference,
                'title' => $command->title,
                'body' => $command->body,
                'due_at' => $command->dueAt?->format('Y-m-d H:i:s'),
            ]);
            $taskId = (string) $pdo->lastInsertId();

            $mapping = $pdo->prepare(
                'INSERT INTO cos_external_references '
                . '(organization_id, provider, entity_type, external_id, cos_reference, last_synced_at) '
                . "VALUES (:organization_id, 'aida', 'task', :external_id, :cos_reference, NOW(6))"
            );
            $mapping->execute([
                'organization_id' => $command->organizationId,
                'external_id' => $taskId,
                'cos_reference' => 'action:' . $command->idempotencyKey,
            ]);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ExternalResult::success($taskId, ['provider' => $this->provider()]);
        } catch (Throwable $exception) {
            if ($ownsTransaction && isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ExternalResult::failure($exception->getMessage(), ['provider' => $this->provider()]);
        }
    }
}
