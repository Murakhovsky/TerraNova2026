<?php
declare(strict_types=1);

use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Application\UseCase\ScheduleSalesMeeting;
use Domains\Sales\Application\UseCase\SendSalesMessage;
use Domains\Sales\Bootstrap\SalesDomainModule;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlDealRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlFollowupRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAgentContextBuilder;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesRuleContextProvider;
use Infrastructure\Integration\Crm\RoutedCrmGateway;

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
define('APP_PATH', $root . '/app');

spl_autoload_register(static function (string $class) use ($root): void {
    foreach ([
        'Kernel\\' => '/app/Kernel/',
        'Domains\\' => '/app/Domains/',
        'Infrastructure\\' => '/app/Infrastructure/',
        'Interfaces\\' => '/app/Interfaces/',
        'Bootstrap\\' => '/app/Bootstrap/',
    ] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});

final class CompositionTestContainer
{
    /** @var array<string, mixed> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $shared = [];

    public function setShared(string $id, mixed $definition): void
    {
        $this->definitions[$id] = $definition;
        unset($this->shared[$id]);
    }

    public function getShared(string $id): mixed
    {
        if (array_key_exists($id, $this->shared)) {
            return $this->shared[$id];
        }
        if (!array_key_exists($id, $this->definitions)) {
            throw new RuntimeException('Missing composition dependency: ' . $id);
        }

        $definition = $this->definitions[$id];
        $value = $definition instanceof Closure ? $definition->call($this) : $definition;
        return $this->shared[$id] = $value;
    }
}

$withoutConstructor = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();

$di = new CompositionTestContainer();
require APP_PATH . '/Bootstrap/SalesServices.php';

// Replace infrastructure-backed dependencies with correctly typed, uninitialized objects.
// The test verifies DI wiring and constructor contracts without opening a database connection.
$di->setShared('cosCrmGateway', $withoutConstructor(RoutedCrmGateway::class));
$di->setShared('salesDealRepository', $withoutConstructor(MysqlDealRepository::class));
$di->setShared('salesFollowupRepository', $withoutConstructor(MysqlFollowupRepository::class));
$di->setShared('salesRuleContextProvider', $withoutConstructor(MysqlSalesRuleContextProvider::class));
$di->setShared('salesAgentContextBuilder', $withoutConstructor(MysqlSalesAgentContextBuilder::class));
$di->setShared('salesChangeDealStage', $withoutConstructor(ChangeDealStage::class));
$di->setShared('salesSendMessage', $withoutConstructor(SendSalesMessage::class));
$di->setShared('salesScheduleMeeting', $withoutConstructor(ScheduleSalesMeeting::class));
$di->setShared('salesAssignDealOwner', $withoutConstructor(AssignDealOwner::class));

$module = $di->getShared('salesDomainModule');
if (!$module instanceof SalesDomainModule) {
    throw new RuntimeException('salesDomainModule did not resolve to SalesDomainModule.');
}
if ($module->name() !== 'sales') {
    throw new RuntimeException('Resolved Sales module has an unexpected module name.');
}
if (count($module->actionHandlers()) < 1) {
    throw new RuntimeException('Resolved Sales module did not build its action handlers.');
}

echo "Sales composition root passed: salesDomainModule resolved with typed dependencies and handlers.\n";
