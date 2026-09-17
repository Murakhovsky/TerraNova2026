<?php

declare(strict_types=1);

use App\Controller\OperationsReadController;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Kernel\Operations\Service\OperationsSectionReader;
use Symfony\Component\HttpFoundation\JsonResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

final class OperationsReadModelStub implements OperationsReadModelInterface
{
    public string $lastOrganizationId = '';
    public int $lastLimit = 0;
    public bool $fail = false;

    public function overview(string $organizationId, int $limit = 30): array
    {
        if ($this->fail) {
            throw new RuntimeException('simulated read-model failure');
        }

        $this->lastOrganizationId = $organizationId;
        $this->lastLimit = $limit;

        return [
            'actions' => [
                ['id' => str_repeat('a', 32), 'status' => 'QUEUED'],
            ],
            'approvals' => [
                ['id' => str_repeat('b', 32), 'status' => 'PENDING'],
            ],
            'agent_runs' => [
                ['id' => str_repeat('c', 32), 'agent_name' => 'sales'],
            ],
            'rules' => [['id' => 'rule-1', 'status' => 'ACTIVE']],
            'events' => [['id' => 'event-1', 'type' => 'TEST']],
            'audit' => [['id' => 'audit-1', 'action' => 'READ']],
        ];
    }

    public function dealIntelligence(string $organizationId, int $dealId): array
    {
        return [];
    }

    public function health(): array
    {
        return ['status' => 'ok'];
    }
}

function payload(JsonResponse $response): array
{
    return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

function expectContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Operations read API contract failed: {$message}\n");
        exit(1);
    }
}

$readModel = new OperationsReadModelStub();
$reader = new OperationsSectionReader($readModel);
$controller = new OperationsReadController($reader, 'tenant-a');

expectContract($reader->section('tenant-a', 'actions', 100) === [
    ['id' => str_repeat('a', 32), 'status' => 'QUEUED'],
], 'shared reader list semantics');
expectContract(($reader->item('tenant-a', 'actions', str_repeat('a', 32), 100)['status'] ?? null) === 'QUEUED', 'shared reader detail semantics');
expectContract($reader->item('tenant-a', 'actions', str_repeat('f', 32), 100) === null, 'shared reader missing detail semantics');

$actions = $controller->actions();
expectContract($actions->getStatusCode() === 200, 'actions status');
expectContract(payload($actions) === [
    'ok' => true,
    'data' => [['id' => str_repeat('a', 32), 'status' => 'QUEUED']],
], 'actions payload parity');
expectContract($readModel->lastOrganizationId === 'tenant-a', 'organization must come from fixed runtime configuration');
expectContract($readModel->lastLimit === 100, 'legacy controller limit parity');

expectContract((payload($controller->approvals())['data'][0]['status'] ?? null) === 'PENDING', 'approvals mapping');
expectContract((payload($controller->agents())['data'][0]['agent_name'] ?? null) === 'sales', 'agents must map to agent_runs');
expectContract((payload($controller->rules())['data'][0]['id'] ?? null) === 'rule-1', 'rules mapping');
expectContract((payload($controller->events())['data'][0]['id'] ?? null) === 'event-1', 'events mapping');
expectContract((payload($controller->audit())['data'][0]['id'] ?? null) === 'audit-1', 'audit mapping');

$action = $controller->action(str_repeat('a', 32));
expectContract($action->getStatusCode() === 200, 'action detail status');
expectContract((payload($action)['data']['status'] ?? null) === 'QUEUED', 'action detail payload');

$missing = $controller->action(str_repeat('f', 32));
expectContract($missing->getStatusCode() === 404, 'missing action status');
expectContract(payload($missing) === ['ok' => false, 'error' => 'Resource not found.'], 'missing action payload parity');

$readModel->fail = true;
$failed = $controller->events();
expectContract($failed->getStatusCode() === 500, 'read-model failure status');
expectContract(payload($failed) === ['ok' => false, 'error' => 'COS runtime query failed.'], 'read-model failure payload parity');

echo "Operations read API and legacy runtime share one section/detail semantic service.\n";
