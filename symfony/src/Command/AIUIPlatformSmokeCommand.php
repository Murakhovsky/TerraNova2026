<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\AI\AgentRunViewFactory;
use App\Web\Experience\AI\UIContextFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Kernel\Action\ActionProposal;
use Kernel\Action\Service\ActionService;
use Kernel\Agent\Contract\AgentRunReadModelInterface;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:ai-ui:smoke',
    description: 'Validate the canonical context-aware Agent UI runtime.',
)]
final class AIUIPlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly PdoConnection $database,
        private readonly AgentRunReadModelInterface $runs,
        private readonly AgentRunViewFactory $views,
        private readonly UIContextFactory $uiContexts,
        private readonly ActionService $actions,
        private readonly Environment $twig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->database->connection();
        $runId = bin2hex(random_bytes(16));
        $dealId = 'wave12-14-' . bin2hex(random_bytes(4));
        $correlationId = 'wave12.14.' . bin2hex(random_bytes(6));
        $unsafe = '<script>globalThis.cosAiOwned=true</script>';

        $statement = $pdo->prepare(
            'INSERT INTO cos_agent_runs '
            . '(id,organization_id,agent_name,agent_version,provider,model,prompt_version,schema_version,'
            . 'subject_type,subject_id,status,context_reference,input_snapshot,output,confidence,input_tokens,'
            . 'output_tokens,cost_amount,cost_currency,duration_ms,error,correlation_id,started_at,finished_at) '
            . "VALUES (:id,'default','sales_assistant','1.0','smoke','structured-test','1','1',"
            . "'sales.deal',:subject_id,'COMPLETED',:context_reference,:input_snapshot,:output,0.91,120,48,"
            . "0.0042,'USD',37,NULL,:correlation_id,NOW(6),NOW(6))"
        );
        $statement->execute([
            'id' => $runId,
            'subject_id' => $dealId,
            'context_reference' => json_encode(['workspace' => 'sales.deal'], JSON_THROW_ON_ERROR),
            'input_snapshot' => json_encode(['subject' => $dealId], JSON_THROW_ON_ERROR),
            'output' => json_encode([
                'summary' => [
                    'decision' => 'follow_up',
                    'reason' => $unsafe,
                ],
                'warnings' => ['Human review required.'],
                'metrics' => [['name' => 'confidence', 'value' => 0.91]],
                'entities' => [['type' => 'sales.deal', 'id' => $dealId]],
                'evidence' => [
                    ['source' => 'crm', 'fact' => 'No reply for 48 hours.'],
                ],
                'proposed_actions' => [[
                    'type' => 'sales.workflow.followup',
                    'target_type' => 'sales.deal',
                    'target_id' => $dealId,
                    'parameters' => ['channel' => 'email'],
                ]],
            ], JSON_THROW_ON_ERROR),
            'correlation_id' => $correlationId,
        ]);

        $action = $this->actions->propose(
            'default',
            new ActionProposal(
                type: 'sales.workflow.followup',
                targetType: 'sales.deal',
                targetId: $dealId,
                parameters: ['channel' => 'email'],
                sourceType: 'AGENT',
                sourceId: $runId,
                executionMode: 'MANUAL',
                riskLevel: 'LOW',
                idempotencyKey: 'wave12.14.' . bin2hex(random_bytes(8)),
            ),
            $correlationId,
        );

        try {
            $run = $this->runs->find('default', $runId);
            if (
                $run === null
                || $run->agentName !== 'sales_assistant'
                || $run->subjectType !== 'sales.deal'
                || $run->confidence !== 0.91
                || $run->correlationId !== $correlationId
            ) {
                $output->writeln('<error>Agent run projection is invalid.</error>');

                return Command::FAILURE;
            }

            $tenant = new TenantContext(
                UserId::fromString('1'),
                OrganizationId::fromString('default'),
                OrganizationRole::fromString('admin'),
                [
                    Permission::fromString(TenantPermissions::ACCESS),
                    Permission::fromString(TenantPermissions::MANAGE),
                    Permission::fromString(TenantPermissions::ADMIN),
                ],
            );
            $context = new WebExtensionContext(
                organizationId: 'default',
                role: 'admin',
                surface: 'ai',
                activeSection: 'sales',
                activeItem: 'sales.deal',
            );
            $entity = new EntityRef('sales.deal', $dealId);

            $uiContext = $this->uiContexts->create(
                tenant: $tenant,
                context: $context,
                workspaceId: 'sales.deal',
                entity: $entity,
                capabilities: ['agent.result.read', 'agent.action.inspect', 'ui.action.propose'],
            );
            if ($uiContext->workspace !== 'sales.deal' || $uiContext->entity?->key() !== $entity->key()) {
                $output->writeln('<error>Headless UIContext is invalid.</error>');

                return Command::FAILURE;
            }

            $view = $this->views->create($tenant, $context, $run);
            if (
                count($view->actions) !== 1
                || $view->actions[0]->action->id !== $action->id
                || $view->actions[0]->uiActions === []
                || $view->actions[0]->uiActions[0]->resourceId !== $action->id
            ) {
                $output->writeln('<error>Agent proposal did not converge into governed UIAction projection.</error>');

                return Command::FAILURE;
            }

            $html = $this->twig->render('experience/ai/ai_panel_frame.html.twig', [
                'runs' => [$view],
                'uiContext' => $uiContext,
                'contextError' => null,
                'workspaceId' => 'sales.deal',
                'entity' => $entity,
            ]);

            foreach (['Headless UIContext', 'Agent runs', 'Governed actions', $correlationId] as $marker) {
                if (!str_contains($html, $marker)) {
                    $output->writeln(sprintf('<error>AI UI runtime marker is missing: %s</error>', $marker));

                    return Command::FAILURE;
                }
            }

            if (str_contains($html, $unsafe) || !str_contains($html, '&lt;script&gt;')) {
                $output->writeln('<error>Agent model output crossed the HTML rendering boundary.</error>');

                return Command::FAILURE;
            }

            $output->writeln('COS AI UI Platform runtime passed.');

            return Command::SUCCESS;
        } finally {
            $deleteAction = $pdo->prepare("DELETE FROM cos_actions WHERE organization_id='default' AND id=:id");
            $deleteAction->execute(['id' => $action->id]);
            $deleteRun = $pdo->prepare("DELETE FROM cos_agent_runs WHERE organization_id='default' AND id=:id");
            $deleteRun->execute(['id' => $runId]);
        }
    }
}
