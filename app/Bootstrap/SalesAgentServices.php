<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlAgentConfigurationProvider;
use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlSalesAgentAdministration;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Agent\Service\SensitiveContextRedactor;
use Kernel\Agent\Service\StructuredDecisionValidator;

$di->setShared('cosAgentConfigurationProvider', fn (): MysqlAgentConfigurationProvider => new MysqlAgentConfigurationProvider(
    $this->getShared('databaseService')->connection(),
));

// Extend the existing Kernel runtime with tenant configuration resolution. The runtime remains Kernel-owned.
$di->setShared('cosAgentRuntime', fn (): AgentRuntime => new AgentRuntime(
    $this->getShared('cosAgentContextBuilder'),
    $this->getShared('cosLlmClient'),
    new StructuredDecisionValidator(),
    $this->getShared('cosAgentRunRepository'),
    $this->getShared('cosDecisionRepository'),
    new SensitiveContextRedactor(),
    $this->getShared('cosAgentConfigurationProvider'),
));

$di->setShared('salesAgentAdministration', fn (): MysqlSalesAgentAdministration => new MysqlSalesAgentAdministration(
    $this->getShared('databaseService')->connection(),
    $this->getShared('cosAgentConfigurationProvider'),
    $this->getShared('cosAgentRuntime'),
));
