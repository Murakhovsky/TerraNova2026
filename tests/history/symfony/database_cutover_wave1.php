<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Migration\Database\PlatformOperationsDatabaseCutover;
use Infrastructure\Llm\MysqlLlmGovernanceRepository;
use Infrastructure\Platform\Persistence\MySql\Operations\MysqlMetricsRecorder;
use Infrastructure\Platform\Persistence\MySql\Resilience\MysqlCircuitBreakerStore;
use PDO;
use RuntimeException;

function wave1Pdo(string $host, int $port, string $database, string $user, string $password): PDO
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

function expectWave1(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$legacy = wave1Pdo(
    (string) getenv('LEGACY_DB_HOST'),
    (int) getenv('LEGACY_DB_PORT'),
    (string) getenv('LEGACY_DB_NAME'),
    (string) getenv('LEGACY_DB_USER'),
    (string) getenv('LEGACY_DB_PASSWORD'),
);
$canonical = wave1Pdo(
    (string) getenv('CANONICAL_DB_HOST'),
    (int) getenv('CANONICAL_DB_PORT'),
    (string) getenv('CANONICAL_DB_NAME'),
    (string) getenv('CANONICAL_DB_USER'),
    (string) getenv('CANONICAL_DB_PASSWORD'),
);

foreach ([
    'cos_llm_budget_reservations',
    'cos_llm_usage',
    'cos_llm_budgets',
    'cos_operational_metrics',
    'cos_external_circuits',
] as $table) {
    $legacy->exec('DROP TABLE IF EXISTS ' . $table);
}

$legacy->exec(<<<'SQL'
CREATE TABLE cos_external_circuits (
    organization_id VARCHAR(64) NOT NULL,
    service_key VARCHAR(128) NOT NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    opened_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, service_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$legacy->exec(<<<'SQL'
CREATE TABLE cos_operational_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NULL,
    metric VARCHAR(160) NOT NULL,
    value DECIMAL(20,6) NOT NULL,
    labels JSON NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$legacy->exec(<<<'SQL'
CREATE TABLE cos_llm_budgets (
    organization_id VARCHAR(40) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    monthly_limit DECIMAL(14,4) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$legacy->exec(<<<'SQL'
CREATE TABLE cos_llm_usage (
    id CHAR(32) NOT NULL,
    organization_id VARCHAR(40) NULL,
    correlation_id VARCHAR(128) NOT NULL,
    use_case VARCHAR(160) NULL,
    provider VARCHAR(80) NOT NULL,
    model VARCHAR(160) NOT NULL,
    input_tokens INT NULL,
    output_tokens INT NULL,
    cost_amount DECIMAL(14,6) NULL,
    cost_currency CHAR(3) NULL,
    latency_ms INT NOT NULL,
    fallback_count INT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$legacy->exec(<<<'SQL'
CREATE TABLE cos_llm_budget_reservations (
    id VARCHAR(64) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    currency CHAR(3) NOT NULL,
    reserved_amount DECIMAL(14,4) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$legacy->exec(
    "INSERT INTO cos_external_circuits (organization_id,service_key,consecutive_failures,opened_until) "
    . "VALUES ('default','openai',2,NULL),('_global','n8n',1,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 SECOND))"
);
$legacy->exec(
    "INSERT INTO cos_operational_metrics (id,organization_id,metric,value,labels,recorded_at) VALUES "
    . "(41,'default','legacy.metric',12.500000,'{\"source\":\"legacy\"}',UTC_TIMESTAMP(6)),"
    . "(42,NULL,'global.metric',1.000000,NULL,UTC_TIMESTAMP(6))"
);
$legacy->exec(
    "INSERT INTO cos_llm_budgets (organization_id,currency,monthly_limit) "
    . "VALUES ('default','USD',100.0000),('default','EUR',50.0000)"
);
$legacy->exec(
    "INSERT INTO cos_llm_usage "
    . "(id,organization_id,correlation_id,use_case,provider,model,input_tokens,output_tokens,cost_amount,cost_currency,latency_ms,fallback_count,created_at) VALUES "
    . "('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','default','corr-wave1','sales.assist','openai','gpt-test',100,20,0.250000,'USD',450,0,UTC_TIMESTAMP(6))"
);
$legacy->exec(
    "INSERT INTO cos_llm_budget_reservations (id,organization_id,currency,reserved_amount,expires_at) "
    . "VALUES ('wave1-reservation','default','USD',1.5000,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 MINUTE))"
);

$canonical->exec("DELETE FROM cos_database_cutover_journal WHERE cutover_id='platform-operations-v1'");
foreach ([
    'cos_llm_budget_reservations',
    'cos_llm_usage',
    'cos_llm_budgets',
    'cos_operational_metrics',
    'cos_external_circuits',
] as $table) {
    $canonical->exec('DELETE FROM ' . $table);
}

$cutover = new PlatformOperationsDatabaseCutover($legacy, $canonical);
$result = $cutover->migrate();
expectWave1(($result['status'] ?? null) === 'COMPLETED', 'Initial Platform operations cutover must complete.');
expectWave1((int) ($result['source']['external_circuits']['count'] ?? -1) === 2, 'Circuit rows were not copied.');
expectWave1((int) ($result['source']['operational_metrics']['count'] ?? -1) === 2, 'Operational metrics were not copied.');
expectWave1((int) ($result['source']['llm_budgets']['count'] ?? -1) === 2, 'LLM budgets were not copied.');
expectWave1((int) ($result['source']['llm_usage']['count'] ?? -1) === 1, 'LLM usage was not copied.');
expectWave1((int) ($result['source']['llm_budget_reservations']['count'] ?? -1) === 1, 'LLM reservations were not copied.');

$second = $cutover->migrate();
expectWave1(($second['status'] ?? null) === 'ALREADY_COMPLETED', 'Wave 1 cutover must be idempotent after journal completion.');

$governance = new MysqlLlmGovernanceRepository($canonical);
expectWave1(abs(($governance->monthlyBudget('default', 'USD') ?? -1.0) - 100.0) < 0.0001, 'Canonical LLM governance must read migrated budget.');
$legacy->exec("UPDATE cos_llm_budgets SET monthly_limit=1.0000 WHERE organization_id='default' AND currency='USD'");
expectWave1(abs(($governance->monthlyBudget('default', 'USD') ?? -1.0) - 100.0) < 0.0001, 'Canonical LLM budget must be independent from later legacy mutations.');

$metrics = new MysqlMetricsRecorder($canonical);
$metrics->record('wave1.canonical', 7.0, 'default', ['cutover' => true]);
$canonicalMetric = (int) $canonical->query("SELECT COUNT(*) FROM cos_operational_metrics WHERE metric='wave1.canonical'")->fetchColumn();
$legacyMetric = (int) $legacy->query("SELECT COUNT(*) FROM cos_operational_metrics WHERE metric='wave1.canonical'")->fetchColumn();
expectWave1($canonicalMetric === 1 && $legacyMetric === 0, 'Metrics recorder must write only to canonical MySQL after cutover.');

$circuits = new MysqlCircuitBreakerStore($canonical);
$circuits->recordSuccess('default', 'openai');
$canonicalFailures = (int) $canonical->query("SELECT consecutive_failures FROM cos_external_circuits WHERE organization_id='default' AND service_key='openai'")->fetchColumn();
$legacyFailures = (int) $legacy->query("SELECT consecutive_failures FROM cos_external_circuits WHERE organization_id='default' AND service_key='openai'")->fetchColumn();
expectWave1($canonicalFailures === 0 && $legacyFailures === 2, 'Circuit breaker state must be independent from legacy MySQL after cutover.');

$status = $canonical->query(
    "SELECT status FROM cos_database_cutover_journal WHERE cutover_id='platform-operations-v1'"
)->fetchColumn();
expectWave1($status === 'COMPLETED', 'Canonical cutover journal must record Wave 1 completion.');

echo "Database cutover Wave 1 dual-MySQL contract passed.\n";
