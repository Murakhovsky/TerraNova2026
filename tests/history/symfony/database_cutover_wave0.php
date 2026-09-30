<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Migration\Database\ModuleRuntimeDatabaseCutover;
use App\Infrastructure\Module\PdoModuleLifecycleRepository;
use App\Infrastructure\Module\PdoModuleStateRepository;
use PDO;
use RuntimeException;

function cutoverPdo(string $host, int $port, string $database, string $user, string $password): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

function expectCutover(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$legacy = cutoverPdo(
    (string) getenv('LEGACY_DB_HOST'),
    (int) getenv('LEGACY_DB_PORT'),
    (string) getenv('LEGACY_DB_NAME'),
    (string) getenv('LEGACY_DB_USER'),
    (string) getenv('LEGACY_DB_PASSWORD'),
);
$canonical = cutoverPdo(
    (string) getenv('CANONICAL_DB_HOST'),
    (int) getenv('CANONICAL_DB_PORT'),
    (string) getenv('CANONICAL_DB_NAME'),
    (string) getenv('CANONICAL_DB_USER'),
    (string) getenv('CANONICAL_DB_PASSWORD'),
);

$legacy->exec('DROP TABLE IF EXISTS cos_module_installations');
$legacy->exec('DROP TABLE IF EXISTS cos_organization_modules');
$legacy->exec("CREATE TABLE cos_organization_modules (organization_id VARCHAR(40) NOT NULL,module_id VARCHAR(80) NOT NULL,enabled TINYINT(1) NOT NULL,configuration_json JSON NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (organization_id,module_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$legacy->exec("CREATE TABLE cos_module_installations (organization_id VARCHAR(64) NOT NULL,module_id VARCHAR(64) NOT NULL,status ENUM('INSTALLED','UNINSTALLED') NOT NULL DEFAULT 'INSTALLED',installed_version VARCHAR(32) NOT NULL,schema_version VARCHAR(32) NOT NULL,installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (organization_id,module_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$legacy->exec("INSERT INTO cos_organization_modules (organization_id,module_id,enabled,configuration_json) VALUES ('default','sales',1,'{\\\"source\\\":\\\"legacy\\\"}'),('default','diagnostic',0,NULL)");
$legacy->exec("INSERT INTO cos_module_installations (organization_id,module_id,status,installed_version,schema_version) VALUES ('default','sales','INSTALLED','0.8.6','0.8.6'),('default','diagnostic','INSTALLED','0.6.1','0.6.1')");

$cutover = new ModuleRuntimeDatabaseCutover($legacy, $canonical);
$result = $cutover->migrate();
expectCutover(($result['status'] ?? null) === 'COMPLETED', 'Initial module runtime cutover must complete.');

$states = new PdoModuleStateRepository($canonical);
expectCutover($states->enabledOverride('default', 'sales') === true, 'Canonical module state must contain legacy Sales enablement.');
expectCutover($states->enabledOverride('default', 'diagnostic') === false, 'Canonical module state must preserve disabled module.');

$lifecycle = new PdoModuleLifecycleRepository($canonical);
expectCutover($lifecycle->find('default', 'sales') !== null, 'Canonical lifecycle repository must contain Sales installation.');

$legacy->exec("UPDATE cos_organization_modules SET enabled=0 WHERE organization_id='default' AND module_id='sales'");
expectCutover($states->enabledOverride('default', 'sales') === true, 'Canonical repository must remain independent after legacy data changes.');

$second = $cutover->migrate();
expectCutover(($second['status'] ?? null) === 'ALREADY_COMPLETED', 'Cutover must be idempotent after journal completion.');

$status = $canonical->query("SELECT status FROM cos_database_cutover_journal WHERE cutover_id='module-runtime-v1'")->fetchColumn();
expectCutover($status === 'COMPLETED', 'Canonical cutover journal must record completion.');

echo "Database cutover Wave 0 dual-MySQL contract passed.\\n";
