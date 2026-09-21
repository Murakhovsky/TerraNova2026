<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Operations\Command\RetryAsyncOperationCommand;
use App\Web\Experience\Async\AsyncOperationWebProvider;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Queue\AsyncOperationStatus;
use Kernel\Event\Service\OutboxPublisher;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:async-operations:smoke',
    description: 'Validate async operation projection, Activity Center and retry runtime.',
)]
final class AsyncOperationsSmokeCommand extends Command
{
    public function __construct(
        private readonly PdoConnection $database,
        private readonly AsyncOperationReadModelInterface $operations,
        private readonly AsyncOperationWebProvider $provider,
        private readonly CommandBusInterface $commands,
        private readonly OutboxPublisher $outbox,
        private readonly RealtimeTopicFactory $topics,
        private readonly Environment $twig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = bin2hex(random_bytes(16));
        $correlationId = bin2hex(random_bytes(16));
        $pdo = $this->database->connection();

        $statement = $pdo->prepare(
            'INSERT INTO cos_jobs '
            . '(id,organization_id,type,payload,status,attempts,max_attempts,timeout_seconds,available_at,'
            . 'locked_at,locked_by,completed_at,last_error,idempotency_key,correlation_id) '
            . "VALUES (:id,'default','WAVE12_ASYNC_SMOKE',:payload,'DEAD',3,3,60,NOW(6),"
            . "NULL,NULL,NULL,'Synthetic Wave 12.13 failure',:idempotency_key,:correlation_id)"
        );
        $statement->execute([
            'id' => $id,
            'payload' => json_encode([
                'subject_type' => 'sales.deal',
                'subject_id' => 'deal-wave12-13',
            ], JSON_THROW_ON_ERROR),
            'idempotency_key' => 'wave12.13.' . $id,
            'correlation_id' => $correlationId,
        ]);

        try {
            $operation = $this->operations->find('default', $id);
            if (
                $operation === null
                || $operation->status !== AsyncOperationStatus::Failed
                || !$operation->canRetry
                || $operation->entityType !== 'sales.deal'
                || $operation->entityId !== 'deal-wave12-13'
                || $operation->correlationId !== $correlationId
            ) {
                $output->writeln('<error>Async operation read projection is invalid.</error>');

                return Command::FAILURE;
            }

            $context = new WebExtensionContext(
                organizationId: 'default',
                role: 'admin',
                surface: 'workspace',
                activeSection: 'cos',
                activeItem: 'activity',
            );

            $activity = $this->provider->activities($context, 100);
            $notifications = $this->provider->notifications($context, 100);
            $activityIds = array_map(static fn ($item): string => $item->id, $activity);
            $notificationIds = array_map(static fn ($item): string => $item->id, $notifications);

            if (
                !in_array('async.operation.' . $id, $activityIds, true)
                || !in_array('async.operation.failure.' . $id, $notificationIds, true)
            ) {
                $output->writeln('<error>Async operation did not enter Activity/Notification projections.</error>');

                return Command::FAILURE;
            }

            $html = $this->twig->render('experience/async/activity_center_frame.html.twig', [
                'tab' => 'activity',
                'activity' => $activity,
                'notifications' => $notifications,
                'selected' => $operation,
                'topic' => $this->topics->organization('default'),
                'retried' => false,
                'csrfToken' => 'wave12-13-smoke',
            ]);

            foreach ([
                'id="cos-activity-center-frame"',
                'WAVE12_ASYNC_SMOKE',
                'Retry operation',
                $correlationId,
                'data-controller="realtime-connection"',
            ] as $marker) {
                if (!str_contains($html, $marker)) {
                    $output->writeln(sprintf('<error>Activity Center runtime marker is missing: %s</error>', $marker));

                    return Command::FAILURE;
                }
            }

            $this->commands->dispatch(new RetryAsyncOperationCommand(
                organizationId: 'default',
                actorId: '1',
                operationId: $id,
            ));

            $retried = $this->operations->find('default', $id);
            if (
                $retried === null
                || $retried->status !== AsyncOperationStatus::Queued
                || $retried->canRetry
                || $retried->attempts !== 0
            ) {
                $output->writeln('<error>Async operation retry did not return the job to queued state.</error>');

                return Command::FAILURE;
            }

            $event = $pdo->prepare(
                "SELECT id FROM cos_events WHERE organization_id='default' "
                . "AND type='kernel.queue.operation.changed' AND aggregate_type='async_operation' "
                . 'AND aggregate_id=:id ORDER BY occurred_at DESC LIMIT 1'
            );
            $event->execute(['id' => $id]);
            $eventId = $event->fetchColumn();
            if (!is_string($eventId) || $eventId === '') {
                $output->writeln('<error>Async operation retry did not append a durable lifecycle event.</error>');

                return Command::FAILURE;
            }

            $delivered = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $this->outbox->runOne('wave12-13-smoke');

                $checkpoint = $pdo->prepare(
                    "SELECT status FROM cos_event_consumptions WHERE event_id=:event_id "
                    . "AND consumer_name='platform.async-operations-realtime.v1' LIMIT 1"
                );
                $checkpoint->execute(['event_id' => $eventId]);
                if ($checkpoint->fetchColumn() === 'COMPLETED') {
                    $delivered = true;
                    break;
                }
            }

            if (!$delivered) {
                $output->writeln('<error>Async operation lifecycle event did not reach the durable realtime consumer.</error>');

                return Command::FAILURE;
            }

            $output->writeln('COS Async Operations runtime passed.');

            return Command::SUCCESS;
        } finally {
            $outbox = $pdo->prepare(
                "DELETE o FROM cos_event_outbox o "
                . "INNER JOIN cos_events e ON e.id=o.event_id "
                . "WHERE e.organization_id='default' AND e.aggregate_type='async_operation' AND e.aggregate_id=:id"
            );
            $outbox->execute(['id' => $id]);

            $consumptions = $pdo->prepare(
                "DELETE c FROM cos_event_consumptions c "
                . "INNER JOIN cos_events e ON e.id=c.event_id "
                . "WHERE e.organization_id='default' AND e.aggregate_type='async_operation' AND e.aggregate_id=:id"
            );
            $consumptions->execute(['id' => $id]);

            $events = $pdo->prepare(
                "DELETE FROM cos_events "
                . "WHERE organization_id='default' AND aggregate_type='async_operation' AND aggregate_id=:id"
            );
            $events->execute(['id' => $id]);

            $delete = $pdo->prepare("DELETE FROM cos_jobs WHERE organization_id='default' AND id=:id");
            $delete->execute(['id' => $id]);
        }
    }
}
