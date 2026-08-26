<?php
declare(strict_types=1);

use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Configuration\Contract\ConfigurationStoreInterface;
use Kernel\Configuration\Service\ConfigurationProvisioner;
use Kernel\Configuration\Service\ConfigurationValidator;
use Kernel\Event\DomainEvent;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Rule\Rule;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Kernel\\')) {
        $file = $root . '/app/Kernel/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) require $file;
    }
});

$module = new class implements DomainModuleInterface {
    public function name(): string { return 'sample'; }
    public function eventTypes(): array { return ['sample.changed']; }
    public function actionTypes(): array { return ['sample.review']; }
    public function actionHandlers(): array { return [new class implements ActionHandlerInterface {
        public function supports(string $actionType): bool { return $actionType === 'sample.review'; }
        public function execute(Action $action): ExecutionResult { return ExecutionResult::success(); }
    }]; }
    public function agents(): array { return []; }
    public function agentContextBuilders(): array { return []; }
    public function ruleContextProvider(): RuleContextProviderInterface { return new class implements RuleContextProviderInterface {
        public function contextFor(DomainEvent $event): array { return []; }
    }; }
    public function rules(string $organizationId): array { return [new Rule(
        'sample-rule', $organizationId, 'Review change', 'sample.changed',
        [['field' => 'event.payload.risk', 'operator' => '>=', 'value' => 5]],
        ['type' => 'CREATE_ACTION', 'action_type' => 'sample.review'],
    )]; }
    public function policies(string $organizationId): array { return [
        new ActionPolicy('sample-policy', $organizationId, 'sample.review', [], PolicyDecision::ApprovalRequired, 10),
    ]; }
};
$registry = new DomainModuleRegistry([$module]);
$store = new class implements ConfigurationStoreInterface {
    public array $calls = [];
    public function provision(string $organizationId, string $domainName, array $rules, array $policies, string $manifestHash, string $actorId): void {
        $this->calls[] = compact('organizationId', 'domainName', 'rules', 'policies', 'manifestHash', 'actorId');
    }
};
$validator = new ConfigurationValidator($registry);
$result = (new ConfigurationProvisioner($registry, $validator, $store))->provision('tenant-a', 'test-suite');
if ($result['domains'] !== 1 || $result['rules'] !== 1 || $result['policies'] !== 1 || count($store->calls) !== 1) {
    throw new RuntimeException('Valid Domain manifest was not provisioned.');
}

$invalidRule = new Rule(
    'invalid-rule', 'tenant-a', 'Invalid', 'sample.changed', [],
    ['type' => 'CREATE_ACTION', 'action_type' => 'foreign.delete_everything'],
);
try {
    $validator->validate($module, 'tenant-a', [$invalidRule], $module->policies('tenant-a'));
    throw new RuntimeException('Configuration accepted an action outside Domain ownership.');
} catch (InvalidArgumentException) {
}

echo "Configuration ownership validation and provisioning passed.\n";

