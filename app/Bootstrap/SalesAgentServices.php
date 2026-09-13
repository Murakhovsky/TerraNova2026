<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlAgentConfigurationProvider;
use Infrastructure\Platform\Persistence\MySql\Configuration\MysqlSalesAgentAdministration;

// Sales owns the tenant-backed agent configuration provider; Kernel owns AgentRuntime assembly.
$di->setShared('cosAgentConfigurationProvider', fn (): MysqlAgentConfigurationProvider => new MysqlAgentConfigurationProvider(
    $this->getShared('databaseService')->connection(),
));

$di->setShared('salesAgentAdministration', fn (): MysqlSalesAgentAdministration => new MysqlSalesAgentAdministration(
    $this->getShared('databaseService')->connection(),
    $this->getShared('cosAgentConfigurationProvider'),
    $this->getShared('cosAgentRuntime'),
));
