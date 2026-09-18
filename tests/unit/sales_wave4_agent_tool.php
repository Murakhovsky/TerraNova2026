<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        foreach ([
            'App\\' => '/symfony/src/',
            'Kernel\\' => '/app/Kernel/',
            'Platform\\' => '/app/Platform/',
            'Domains\\' => '/app/Domains/',
            'Infrastructure\\' => '/app/Infrastructure/',
        ] as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) continue;
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    });
}

use App\Infrastructure\AI\EnvironmentSalesAgentLlmClient;
use Domains\Sales\Automation\Agent\SalesIntelligenceAgent;
use Domains\Sales\Automation\Agent\SalesIntelligenceResultValidator;
use Kernel\Agent\Service\StructuredDecisionValidator;

function wave4(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$definition = SalesIntelligenceAgent::definition();
$client = new EnvironmentSalesAgentLlmClient('fixture', '', '', 'wave4-fixture', 'fixture');
$response = $client->structuredForOrganization(
    'default',
    $definition,
    'Recommend the safest next action.',
    ['deal' => ['id' => '101']],
    'wave4-agent-corr',
);

$result = (new StructuredDecisionValidator())->validate($response->output, $definition);
(new SalesIntelligenceResultValidator())->validate($result, $definition);

wave4($response->provider === 'fixture', 'Wave 4 test Agent must not call an external provider.');
wave4($result->confidence === 0.92, 'Wave 4 deterministic confidence changed unexpectedly.');
wave4(count($result->proposedActions) === 1, 'Wave 4 fixture must produce exactly one governed proposal.');
wave4($result->proposedActions[0]['type'] === 'sales.request_manager_review', 'Wave 4 fixture proposed the wrong Sales action.');
wave4($result->proposedActions[0]['target_type'] === 'deal', 'Wave 4 fixture must target a Deal.');
wave4($result->proposedActions[0]['target_id'] === '101', 'Wave 4 fixture must preserve the Sales subject.');
wave4(isset($result->evidence['sales_intelligence']), 'Wave 4 output must satisfy the Sales intelligence evidence schema.');

echo "Sales Wave 4 deterministic Agent output contract passed.\n";
