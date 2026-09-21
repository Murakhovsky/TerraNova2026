<?php

declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Contract\ActivityHistoryRepositoryInterface;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Model\ActivitySource;
use Platform\Audit\Model\ActivityStatus;
use Platform\Audit\Model\Actor;
use Platform\Audit\Model\ActorKind;
use Platform\Audit\Model\ResourceReference;
use Platform\Audit\Service\AuditRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:platform:audit-history:smoke',
    description: 'Validate canonical activity source, correlation and tenant-scoped history.',
)]
final class AuditHistoryPlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ActivityHistoryRepositoryInterface $history,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = OrganizationId::fromString('default');
        $otherOrganizationId = OrganizationId::fromString('wave12-21-other');
        $token = bin2hex(random_bytes(8));
        $correlationId = 'w1221c-' . $token;
        $resource = new ResourceReference('wave12_audit_smoke', 'r-' . $token);
        $now = new DateTimeImmutable();

        $human = new Actor('user', 'wave12-human');
        if ($human->kind() !== ActorKind::HUMAN) {
            $output->writeln('<error>User actor must be classified as human.</error>');

            return Command::FAILURE;
        }

        $this->audit->record(new ActivityRecord(
            id: 'w1221h-' . $token,
            organizationId: $organizationId,
            actor: $human,
            action: 'audit.smoke.human',
            resource: $resource,
            input: ['intent' => 'verify'],
            output: ['accepted' => true],
            agent: null,
            tool: null,
            workflow: null,
            durationMs: 1,
            cost: null,
            costUnit: null,
            status: ActivityStatus::SUCCESS,
            error: null,
            correlationId: $correlationId,
            timestamp: $now,
            metadata: ['smoke' => true],
            source: ActivitySource::HUMAN,
        ));

        $agent = new Actor('agent', 'wave12-agent');
        if ($agent->kind() !== ActorKind::AGENT) {
            $output->writeln('<error>Agent actor must remain distinguishable from a human.</error>');

            return Command::FAILURE;
        }

        $this->audit->record(new ActivityRecord(
            id: 'w1221a-' . $token,
            organizationId: $organizationId,
            actor: $agent,
            action: 'audit.smoke.agent',
            resource: $resource,
            input: ['intent' => 'verify'],
            output: ['accepted' => true],
            agent: 'wave12-agent',
            tool: null,
            workflow: null,
            durationMs: 2,
            cost: null,
            costUnit: null,
            status: ActivityStatus::SUCCESS,
            error: null,
            correlationId: $correlationId,
            timestamp: $now->modify('+1 microsecond'),
            metadata: ['smoke' => true],
            source: ActivitySource::AGENT,
        ));

        $correlated = $this->history->byCorrelationId($organizationId, $correlationId);
        if (count($correlated) !== 2) {
            $output->writeln('<error>Correlation history must return both related activities.</error>');

            return Command::FAILURE;
        }

        $sources = array_map(static fn ($entry): ActivitySource => $entry->source, $correlated);
        if (!in_array(ActivitySource::HUMAN, $sources, true) || !in_array(ActivitySource::AGENT, $sources, true)) {
            $output->writeln('<error>History must preserve human/agent source provenance.</error>');

            return Command::FAILURE;
        }

        $resourceHistory = $this->history->recentForResource($organizationId, $resource);
        if (count($resourceHistory) !== 2) {
            $output->writeln('<error>Resource history must expose the same durable activity chain.</error>');

            return Command::FAILURE;
        }

        if ($this->history->byCorrelationId($otherOrganizationId, $correlationId) !== []) {
            $output->writeln('<error>Audit history must never cross organization boundaries.</error>');

            return Command::FAILURE;
        }

        $output->writeln('COS Audit / History runtime passed.');

        return Command::SUCCESS;
    }
}
