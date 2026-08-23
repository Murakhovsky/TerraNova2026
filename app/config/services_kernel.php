<?php
declare(strict_types=1);

use Infrastructure\Database\Event\MysqlEventOutbox;
use Infrastructure\Database\Event\MysqlEventStore;
use Infrastructure\Database\Transaction\TransactionManager;
use Infrastructure\Database\Rule\MysqlActionProposalSink;
use Infrastructure\Database\Rule\MysqlRuleEvaluationRepository;
use Infrastructure\Database\Rule\MysqlRuleRepository;
use Infrastructure\Database\Rule\MysqlSalesRuleContextProvider;
use Infrastructure\Crm\Aida\AidaCrmAdapter;
use Infrastructure\Crm\CrmRegistry;
use Infrastructure\Crm\MysqlOrganizationCrmResolver;
use Infrastructure\Crm\RoutedCrmGateway;
use Domains\Sales\Action\CreateFollowupTaskHandler;
use Domains\Sales\Rule\SalesDeterministicProcessCatalog;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Infrastructure\Database\Action\MysqlActionRepository;
use Kernel\Event\Service\EventDispatcher;
use Kernel\Event\EventBus;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Rule\Service\DeterministicProcessEngine;
use Kernel\Rule\Service\RuleEngine;
use Kernel\Rule\Service\RuleEngineEventHandler;
use Infrastructure\Database\Agent\MysqlAgentRunRepository;
use Infrastructure\Database\Agent\MysqlSalesAgentContextBuilder;
use Infrastructure\Llm\HttpStructuredLlmClient;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Agent\Service\StructuredDecisionValidator;
use Domains\Sales\Agent\SalesIntelligenceAgent;

$di->setShared('cosConditionEvaluator', static fn (): ConditionEvaluator => new ConditionEvaluator());

$di->setShared('cosRuleEngine', function (): RuleEngine {
    return new RuleEngine($this->getShared('cosConditionEvaluator'));
});

$di->setShared('cosDeterministicProcessEngine', function (): DeterministicProcessEngine {
    return new DeterministicProcessEngine($this->getShared('cosConditionEvaluator'));
});

$di->setShared('salesDeterministicProcessCatalog', static fn (): SalesDeterministicProcessCatalog =>
    new SalesDeterministicProcessCatalog()
);

$di->setShared('cosPolicyEngine', function (): PolicyEngine {
    return new PolicyEngine($this->getShared('cosConditionEvaluator'));
});

$di->setShared('cosEventDispatcher', static fn (): EventDispatcher => new EventDispatcher());

$di->setShared('eventStore', function (): MysqlEventStore {
    return new MysqlEventStore($this->getShared('databaseService')->connection());
});

$di->setShared('eventBus', function (): EventBus {
    $bus = new EventBus(
        $this->getShared('eventStore'),
        $this->getShared('cosTransactionManager'),
    );
    $bus->subscribe('*', $this->getShared('cosRuleEngineEventHandler'));
    return $bus;
});

$di->setShared('cosRuleRepository', function (): MysqlRuleRepository {
    return new MysqlRuleRepository($this->getShared('databaseService')->connection());
});

$di->setShared('cosRuleContextProvider', function (): MysqlSalesRuleContextProvider {
    return new MysqlSalesRuleContextProvider($this->getShared('databaseService')->connection());
});

$di->setShared('cosRuleEvaluationRepository', function (): MysqlRuleEvaluationRepository {
    return new MysqlRuleEvaluationRepository($this->getShared('databaseService')->connection());
});

$di->setShared('cosActionProposalSink', function (): MysqlActionProposalSink {
    return new MysqlActionProposalSink($this->getShared('cosActionService'));
});

$di->setShared('cosRuleEngineEventHandler', function (): RuleEngineEventHandler {
    return new RuleEngineEventHandler(
        $this->getShared('cosRuleRepository'),
        $this->getShared('cosRuleContextProvider'),
        $this->getShared('cosRuleEvaluationRepository'),
        $this->getShared('cosActionProposalSink'),
        $this->getShared('cosDeterministicProcessEngine'),
    );
});

$di->setShared('cosTransactionManager', function (): TransactionManager {
    return new TransactionManager($this->getShared('databaseService')->connection());
});

$di->setShared('cosEventOutbox', function (): MysqlEventOutbox {
    return new MysqlEventOutbox($this->getShared('databaseService')->connection());
});

$di->setShared('salesTaskActionHandler', function (): CreateFollowupTaskHandler {
    return new CreateFollowupTaskHandler($this->getShared('cosCrmGateway'));
});

$di->setShared('aidaCrmAdapter', function (): AidaCrmAdapter {
    return new AidaCrmAdapter($this->getShared('databaseService'));
});

$di->setShared('cosCrmRegistry', function (): CrmRegistry {
    return new CrmRegistry([$this->getShared('aidaCrmAdapter')]);
});

$di->setShared('cosOrganizationCrmResolver', function (): MysqlOrganizationCrmResolver {
    return new MysqlOrganizationCrmResolver($this->getShared('databaseService'));
});

$di->setShared('cosCrmGateway', function (): RoutedCrmGateway {
    return new RoutedCrmGateway(
        $this->getShared('cosOrganizationCrmResolver'),
        $this->getShared('cosCrmRegistry'),
    );
});

$di->setShared('cosActionExecutor', function (): ActionExecutor {
    return new ActionExecutor([$this->getShared('salesTaskActionHandler')]);
});

$di->setShared('cosActionRepository', function (): MysqlActionRepository {
    return new MysqlActionRepository($this->getShared('databaseService')->connection());
});

$di->setShared('cosActionService', function (): ActionService {
    return new ActionService(
        $this->getShared('cosActionRepository'),
        $this->getShared('cosActionExecutor'),
    );
});

$di->setShared('cosAgentContextBuilder', function (): MysqlSalesAgentContextBuilder {
    return new MysqlSalesAgentContextBuilder($this->getShared('databaseService')->connection());
});

$di->setShared('cosAgentRunRepository', function (): MysqlAgentRunRepository {
    return new MysqlAgentRunRepository($this->getShared('databaseService')->connection());
});

$di->setShared('cosLlmClient', function (): HttpStructuredLlmClient {
    $config = $this->getConfig()->llm;
    return new HttpStructuredLlmClient(
        (string) $config->endpoint,
        (string) $config->token,
        (string) $config->model,
        (string) $config->provider,
    );
});

$di->setShared('cosAgentRuntime', function (): AgentRuntime {
    return new AgentRuntime(
        $this->getShared('cosAgentContextBuilder'),
        $this->getShared('cosLlmClient'),
        new StructuredDecisionValidator(),
        $this->getShared('cosAgentRunRepository'),
    );
});

$di->setShared('salesIntelligenceAgent', static fn () => SalesIntelligenceAgent::definition());
