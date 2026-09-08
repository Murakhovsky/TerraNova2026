<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository;
use Infrastructure\Platform\Persistence\MySql\Agent\MysqlAgentRunRepository;
use Infrastructure\Platform\Persistence\MySql\Agent\MysqlAgentRetention;
use Infrastructure\Platform\Persistence\MySql\Agent\MysqlDecisionRepository;
use Infrastructure\Platform\Persistence\MySql\Approval\MysqlApprovalRepository;
use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlConfigurationStore;
use Infrastructure\Platform\Persistence\MySql\Audit\MysqlAuditRepository;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventOutbox;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventConsumptionRepository;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Infrastructure\Platform\Persistence\MySql\Migration\MigrationRunner;
use Infrastructure\Platform\Persistence\MySql\Migration\SqlStatementSplitter;
use Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyEvaluationRepository;
use Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyRepository;
use Infrastructure\Platform\Persistence\MySql\Queue\MysqlJobQueue;
use Infrastructure\Platform\Persistence\MySql\Rule\MysqlRuleEvaluationRepository;
use Infrastructure\Platform\Persistence\MySql\Rule\MysqlRuleRepository;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;
use Infrastructure\Platform\Persistence\MySql\MysqlExternalReferenceStore;
use Infrastructure\Integration\Crm\Aida\AidaCrmAdapter;
use Infrastructure\Integration\Crm\CrmRegistry;
use Infrastructure\Integration\Crm\EnvironmentCrmWebhookSecretResolver;
use Infrastructure\Integration\Crm\MysqlCrmInboundApplier;
use Infrastructure\Integration\Crm\MysqlCrmInboxRepository;
use Infrastructure\Integration\Crm\MysqlOrganizationCrmResolver;
use Infrastructure\Integration\Crm\RoutedCrmGateway;
use Infrastructure\Llm\HttpStructuredLlmClient;
use Infrastructure\Observability\JsonFileLogger;
use Infrastructure\Platform\Persistence\MySql\Operations\MysqlMetricsRecorder;
use Infrastructure\Platform\ReadModel\MySql\MysqlOperationsReadModel;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlFollowupRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlMessageGateway;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAgentContextBuilder;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesActivityRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesRuleContextProvider;

$connection = static fn ($container) => $container->getShared('databaseService')->connection();

$di->setShared('cosTransactionManager', fn (): TransactionManager => new TransactionManager($connection($this)));
$di->setShared('cosMigrationRunner', fn (): MigrationRunner => new MigrationRunner(
    (new \Infrastructure\Platform\Persistence\Pdo\PdoConnection($this->getConfig()->database))->connection(),
    APP_PATH . '/migrations',
    new SqlStatementSplitter(),
));
$di->setShared('eventStore', fn (): MysqlEventStore => new MysqlEventStore($connection($this)));
$di->setShared('cosEventOutbox', fn (): MysqlEventOutbox => new MysqlEventOutbox($connection($this)));
$di->setShared('cosEventConsumptions', fn (): MysqlEventConsumptionRepository => new MysqlEventConsumptionRepository($connection($this)));
$di->setShared('cosRuleRepository', fn (): MysqlRuleRepository => new MysqlRuleRepository($connection($this)));
$di->setShared('cosRuleEvaluationRepository', fn (): MysqlRuleEvaluationRepository => new MysqlRuleEvaluationRepository($connection($this)));
$di->setShared('cosAuditRepository', fn (): MysqlAuditRepository => new MysqlAuditRepository($connection($this)));
$di->setShared('cosActionRepository', fn (): MysqlActionRepository => new MysqlActionRepository($connection($this)));
$di->setShared('cosPolicyRepository', fn (): MysqlPolicyRepository => new MysqlPolicyRepository($connection($this)));
$di->setShared('cosPolicyEvaluationRepository', fn (): MysqlPolicyEvaluationRepository => new MysqlPolicyEvaluationRepository($connection($this)));
$di->setShared('cosApprovalRepository', fn (): MysqlApprovalRepository => new MysqlApprovalRepository($connection($this)));
$di->setShared('cosAgentRunRepository', fn (): MysqlAgentRunRepository => new MysqlAgentRunRepository(
    $connection($this),
    (int) $this->getConfig()->agent->inputRetentionDays,
));
$di->setShared('cosAgentRetention', fn (): MysqlAgentRetention => new MysqlAgentRetention($connection($this)));
$di->setShared('cosDecisionRepository', fn (): MysqlDecisionRepository => new MysqlDecisionRepository($connection($this)));
$di->setShared('cosJobQueue', fn (): MysqlJobQueue => new MysqlJobQueue($connection($this)));
$di->setShared('cosConfigurationStore', fn (): MysqlConfigurationStore => new MysqlConfigurationStore($connection($this)));
$di->setShared('cosOperationsReadModel', fn (): MysqlOperationsReadModel => new MysqlOperationsReadModel($connection($this)));
$di->setShared('cosMetrics', fn (): MysqlMetricsRecorder => new MysqlMetricsRecorder($connection($this)));
$di->setShared('cosLogger', fn (): JsonFileLogger => new JsonFileLogger(BASE_PATH . '/tmp/logs/cos.jsonl'));

$di->setShared('externalReferenceStore', fn (): MysqlExternalReferenceStore => new MysqlExternalReferenceStore($connection($this)));
$di->setShared('salesMessageGateway', fn (): MysqlMessageGateway => new MysqlMessageGateway(
    $connection($this), $this->getShared('externalReferenceStore'),
));
$di->setShared('salesFollowupRepository', fn (): MysqlFollowupRepository => new MysqlFollowupRepository(
    $connection($this), $this->getShared('externalReferenceStore'),
));
$di->setShared('salesRuleContextProvider', fn (): MysqlSalesRuleContextProvider => new MysqlSalesRuleContextProvider($connection($this)));
$di->setShared('salesAgentContextBuilder', fn (): MysqlSalesAgentContextBuilder => new MysqlSalesAgentContextBuilder($connection($this)));
$di->setShared('salesActivityRepository', fn (): MysqlSalesActivityRepository => new MysqlSalesActivityRepository($connection($this)));

$di->setShared('aidaCrmAdapter', fn (): AidaCrmAdapter => new AidaCrmAdapter(
    $connection($this), $this->getShared('externalReferenceStore'),
));
$di->setShared('cosCrmRegistry', fn (): CrmRegistry => new CrmRegistry([$this->getShared('aidaCrmAdapter')]));
$di->setShared('cosOrganizationCrmResolver', fn (): MysqlOrganizationCrmResolver => new MysqlOrganizationCrmResolver($connection($this)));
$di->setShared('cosCrmGateway', fn (): RoutedCrmGateway => new RoutedCrmGateway(
    $this->getShared('cosOrganizationCrmResolver'),
    $this->getShared('cosCrmRegistry'),
));
$di->setShared('cosCrmInbox', fn (): MysqlCrmInboxRepository => new MysqlCrmInboxRepository($connection($this)));
$di->setShared('cosCrmWebhookSecrets', fn (): EnvironmentCrmWebhookSecretResolver => new EnvironmentCrmWebhookSecretResolver($connection($this)));
$di->setShared('cosCrmInboundApplier', fn (): MysqlCrmInboundApplier => new MysqlCrmInboundApplier($connection($this)));

$di->setShared('cosLlmClient', function (): HttpStructuredLlmClient {
    $config = $this->getConfig()->llm;
    return new HttpStructuredLlmClient(
        (string) $config->endpoint,
        (string) $config->token,
        (string) $config->model,
        (string) $config->provider,
    );
});
