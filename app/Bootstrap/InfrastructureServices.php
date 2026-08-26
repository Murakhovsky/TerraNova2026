<?php
declare(strict_types=1);

use Infrastructure\Database\Action\MysqlActionRepository;
use Infrastructure\Database\Agent\MysqlAgentRunRepository;
use Infrastructure\Database\Agent\MysqlAgentRetention;
use Infrastructure\Database\Agent\MysqlDecisionRepository;
use Infrastructure\Database\Approval\MysqlApprovalRepository;
use Infrastructure\Database\Configuration\MysqlConfigurationStore;
use Infrastructure\Database\Audit\MysqlAuditRepository;
use Infrastructure\Database\Event\MysqlEventOutbox;
use Infrastructure\Database\Event\MysqlEventConsumptionRepository;
use Infrastructure\Database\Event\MysqlEventStore;
use Infrastructure\Database\Migration\MigrationRunner;
use Infrastructure\Database\Migration\SqlStatementSplitter;
use Infrastructure\Database\Policy\MysqlPolicyEvaluationRepository;
use Infrastructure\Database\Policy\MysqlPolicyRepository;
use Infrastructure\Database\Queue\MysqlJobQueue;
use Infrastructure\Database\Rule\MysqlRuleEvaluationRepository;
use Infrastructure\Database\Rule\MysqlRuleRepository;
use Infrastructure\Database\Transaction\TransactionManager;
use Infrastructure\Integration\Crm\Aida\AidaCrmAdapter;
use Infrastructure\Integration\Crm\CrmRegistry;
use Infrastructure\Integration\Crm\EnvironmentCrmWebhookSecretResolver;
use Infrastructure\Integration\Crm\MysqlCrmInboundApplier;
use Infrastructure\Integration\Crm\MysqlCrmInboxRepository;
use Infrastructure\Integration\Crm\MysqlOrganizationCrmResolver;
use Infrastructure\Integration\Crm\RoutedCrmGateway;
use Infrastructure\Llm\HttpStructuredLlmClient;
use Infrastructure\Observability\JsonFileLogger;
use Infrastructure\Operations\MysqlMetricsRecorder;
use Infrastructure\ReadModel\MySql\MysqlOperationsReadModel;
use Infrastructure\Persistence\MySql\Sales\MysqlDealRepository;
use Infrastructure\Persistence\MySql\Sales\MysqlFollowupRepository;
use Infrastructure\Persistence\MySql\Sales\MysqlMessageGateway;
use Infrastructure\Persistence\MySql\Sales\MysqlSalesAgentContextBuilder;
use Infrastructure\Persistence\MySql\Sales\MysqlSalesActivityRepository;
use Infrastructure\Persistence\MySql\Sales\MysqlSalesRuleContextProvider;

$connection = static fn ($container) => $container->getShared('databaseService')->connection();

$di->setShared('cosTransactionManager', fn (): TransactionManager => new TransactionManager($connection($this)));
$di->setShared('cosMigrationRunner', fn (): MigrationRunner => new MigrationRunner(
    $connection($this),
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
