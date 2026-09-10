<?php
declare(strict_types=1);

use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Phalcon\Di\FactoryDefault;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$organizationId = trim((string) ($argv[1] ?? ''));
$userId = trim((string) ($argv[2] ?? 'system-methodology-import'));
if ($organizationId === '') {fwrite(STDERR, "Usage: php bin/import-sales-methodology-v02.php <organization-id> [user-id]\n"); exit(2);}

$di = new FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';
/** @var PdoConnection $database */
$database = $di->getShared('databaseService');
/** @var MethodologyStudioService $studio */
$studio = $di->getShared('diagnosticMethodologyStudio');
$source = json_decode((string) file_get_contents(BASE_PATH . '/resources/diagnostic/sales/0.1.0/sales-diagnostic-pack.json'), true, 512, JSON_THROW_ON_ERROR);
$overrides = json_decode((string) file_get_contents(BASE_PATH . '/resources/diagnostic/sales/0.2.0/studio-overrides.json'), true, 512, JSON_THROW_ON_ERROR);
$scenarioTemplates = json_decode((string) file_get_contents(BASE_PATH . '/resources/diagnostic/sales/0.2.0/regression-scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
$map = ['sections'=>'AREA','criteria'=>'CRITERION','facts'=>'FACT','metrics'=>'METRIC','questions'=>'QUESTION','evidence_requirements'=>'EVIDENCE_REQUIREMENT','rules'=>'RULE','dependencies'=>'DEPENDENCY','recommendations'=>'RECOMMENDATION','benchmarks'=>'BENCHMARK','scoring'=>'SCORING'];

$pdo = $database->connection();
$pdo->beginTransaction();
try {
    $studio->create($organizationId, ['slug'=>'sales','name'=>$overrides['pack']['name'],'domain'=>$overrides['pack']['domain'],'description'=>$overrides['pack']['description'],'methodology_version'=>'0.2.0'], $userId);
    foreach ($map as $field => $type) foreach ($source[$field] ?? [] as $position => $entity) {
        if ($type === 'METRIC') {unset($entity['formula']); $entity = array_replace($entity, $overrides['metrics'][$entity['id']] ?? []);}
        if ($type === 'RECOMMENDATION') {
            $criterion = (string) (($entity['criteria'][0] ?? ''));
            $missingRule = $criterion === '' ? '' : 'rule-' . $criterion . '-missing';
            if ($missingRule !== '' && in_array($missingRule, array_column($source['rules'] ?? [], 'id'), true)) {
                $entity['trigger_rules'] = array_values(array_unique(array_merge($entity['trigger_rules'] ?? [], [$missingRule])));
            }
        }
        $studio->saveEntity($organizationId, 'sales', '0.2.0', $type, $entity + ['order' => $position], $userId);
    }
    $healthyFacts = array_fill_keys(array_column($source['facts'], 'id'), true);
    $healthyMetrics = [];
    foreach ($source['metrics'] as $metric) $healthyMetrics[$metric['id']] = ($metric['direction'] ?? '') === 'lower_is_better' ? 10 : 90;
    $studio->saveScenario($organizationId, 'sales', '0.2.0', ['id'=>'healthy-baseline','name'=>'Healthy sales operating system','input'=>['facts'=>$healthyFacts,'metrics'=>$healthyMetrics],'expected'=>['findings'=>[],'recommendations'=>[],'score_min'=>75]], $userId);
    foreach ($scenarioTemplates['scenarios'] ?? [] as $scenario) {
        $facts = array_replace($healthyFacts, is_array($scenario['fact_overrides'] ?? null) ? $scenario['fact_overrides'] : []);
        foreach (is_array($scenario['unset_facts'] ?? null) ? $scenario['unset_facts'] : [] as $factId) unset($facts[(string) $factId]);
        $metrics = array_replace($healthyMetrics, is_array($scenario['metric_overrides'] ?? null) ? $scenario['metric_overrides'] : []);
        $studio->saveScenario($organizationId, 'sales', '0.2.0', ['id'=>(string)$scenario['id'],'name'=>(string)$scenario['name'],'input'=>['facts'=>$facts,'metrics'=>$metrics],'expected'=>is_array($scenario['expected']??null)?$scenario['expected']:[]], $userId);
    }
    $pdo->commit();
} catch (Throwable $exception) {$pdo->rollBack(); throw $exception;}
$validation = $studio->validate($organizationId, 'sales', '0.2.0');
if (!$validation['valid']) {
    throw new RuntimeException('Imported Sales v0.2.0 is invalid: ' . json_encode($validation['errors'], JSON_THROW_ON_ERROR));
}
$regression = $studio->runRegression($organizationId, 'sales', '0.2.0');
if ($regression['passed'] < 1 || $regression['failed'] > 0 || $regression['changed'] > 0) {
    throw new RuntimeException('Imported Sales v0.2.0 failed regression: ' . json_encode($regression, JSON_THROW_ON_ERROR));
}
echo "Imported and validated Sales Diagnostic Pack v0.2.0; {$regression['passed']} scenario(s) PASSED.\n";
