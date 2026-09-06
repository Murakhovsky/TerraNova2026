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
$map = ['sections'=>'AREA','criteria'=>'CRITERION','facts'=>'FACT','metrics'=>'METRIC','questions'=>'QUESTION','evidence_requirements'=>'EVIDENCE_REQUIREMENT','rules'=>'RULE','dependencies'=>'DEPENDENCY','recommendations'=>'RECOMMENDATION','benchmarks'=>'BENCHMARK','scoring'=>'SCORING'];

$pdo = $database->connection();
$pdo->beginTransaction();
try {
    $studio->create($organizationId, ['slug'=>'sales','name'=>'Sales Diagnostic','domain'=>'Sales','description'=>'Production sales operating-system diagnostic methodology.','methodology_version'=>'0.2.0'], $userId);
    foreach ($map as $field => $type) foreach ($source[$field] ?? [] as $position => $entity) {
        $studio->saveEntity($organizationId, 'sales', '0.2.0', $type, $entity + ['order' => $position], $userId);
    }
    $pdo->commit();
} catch (Throwable $exception) {$pdo->rollBack(); throw $exception;}
echo "Imported Sales Diagnostic Pack v0.2.0 as a Studio draft. Validate, simulate and publish it in Methodology Studio.\n";
