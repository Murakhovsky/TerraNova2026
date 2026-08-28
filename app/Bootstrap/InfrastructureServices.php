<?php
declare(strict_types=1);

use Infrastructure\Persistence\MySql\Database\Action\MysqlActionRepository;
use Infrastructure\Persistence\MySql\Database\Agent\MysqlAgentRunRepository;
use Infrastructure\Persistence\MySql\Database\Agent\MysqlAgentRetention;
use Infrastructure\Persistence\MySql\Database\Agent\MysqlDecisionRepository;
use Infrastructure\Persistence\MySql\Database\Approval\MysqlApprovalRepository;
use Infrastructure\Persistence\MySql\Database\Configuration\MysqlConfigurationStore;
use Infrastructure\Persistence\MySql\Database\Audit\MysqlAuditRepository;
use Infrastructure\Persistence\MySql\Database\Event\MysqlEventOutbox;
use Infrastructure\Persistence\MySql\Database\Event\MysqlEventConsumptionRepository;
use Infrastructure\Persistence\MySql\Database\Event\MysqlEventStore;
use Infrastructure\Persistence\MySql\Database\Migration\MigrationRunner;
use Infrastructure\Persistence\MySql\Database\Migration\SqlStatementSplitter;
use Infrastructure\Persistence\MySql\Database\Policy\MysqlPolicyEvaluationRepository;
use Infrastructure\Persistence\MySql\Database\Policy\MysqlPolicyRepository;
use Infrastructure\Persistence\MySql\Database\Queue\MysqlJobQueue;
use Infrastructure\Persistence\MySql\Database\Rule\MysqlRuleEvaluationRepository;
use Infrastructure\Persistence\MySql\Database\Rule\MysqlRuleRepository;
use Infrastructure\Persistence\MySql\Database\Transaction\TransactionManager;
use Infrastructure\Integration\Crm\Aida\AidaCrmAdapter;
use Infrastructure\Integration\Crm\CrmRegistry;
use Infrastructure\Integration\Crm\EnvironmentCrmWebhookSecretResolver;
use Infrastructure\Integration\Crm\MysqlCrmInboundApplier;
use Infrastructure\Integration\Crm\MysqlCrmInboxRepository;
use Infrastructure\Integration\Crm\MysqlOrganizationCrmResolver;
use Infrastructure\Integration\Crm\RoutedCrmGateway;
use Infrastructure\Llm\HttpStructuredLlmClient;
use Infrastructure\Observability\JsonFileLogger;
use Infrastructure\Persistence\MySql\Operations\MysqlMetricsRecorder;
use Infrastructure\Persistence\MySql\ReadModel\MysqlOperationsReadModel;
use Infrastructure\Persistence\MySql\Sales\MysqlDealRepository;
use Infrastructure\Persistence\MySql\Sales\MysqlFollowupRepository;
use Infrastructure\Persistence\MySql\Sales\MysqlMessageGateway;
use Infrastructure\Persistence\MySql\Sales\MysqlSalesAgentContextBuilder;
use Infrastructure\Persistence\MySql\Sales\MysqlSalesActivityRepository;
use Infrastructure\Persistence\MySql\Sales\MysqlSalesRuleContextProvider;

$connection = static fn ($container) => $container->getShared('databaseService')->connection();

$di->setShared('cosTransactionManager', fn (): TransactionManager => new TransactionManager($connection($this)));
$di->setShared('cosMigrationRunner', fn (): MigrationRunner => new MigrationRunner(
    (new \Infrastructure\Persistence\MySql\Database\Connection\DatabaseService($this->getConfig()->database))->connection(),
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

$di->setShared('salesDealRepository', fn (): MysqlDealRepository => new MysqlDealRepository($connection($this)));
$di->setShared('salesMessageGateway', fn (): MysqlMessageGateway => new MysqlMessageGateway($connection($this)));
$di->setShared('salesFollowupRepository', fn (): MysqlFollowupRepository => new MysqlFollowupRepository($connection($this)));
$di->setShared('salesRuleContextProvider', fn (): MysqlSalesRuleContextProvider => new MysqlSalesRuleContextProvider($connection($this)));
$di->setShared('salesAgentContextBuilder', fn (): MysqlSalesAgentContextBuilder => new MysqlSalesAgentContextBuilder($connection($this)));
$di->setShared('salesActivityRepository', fn (): MysqlSalesActivityRepository => new MysqlSalesActivityRepository($connection($this)));

$di->setShared('aidaCrmAdapter', fn (): AidaCrmAdapter => new AidaCrmAdapter($connection($this)));
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
