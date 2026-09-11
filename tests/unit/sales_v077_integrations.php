<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Domains\Sales\Application\Contract\SalesIntegrationHealthProbeInterface;
use Domains\Sales\Automation\Integration\SalesIntegrationDefinitionCatalog;

$admin = new ReflectionClass(SalesIntegrationAdministrationInterface::class);
foreach (['catalog', 'integrations', 'routingOptions', 'integration', 'create', 'update', 'testConnection', 'saveRoute', 'revisions'] as $method) {
    if (!$admin->hasMethod($method)) {
        throw new RuntimeException('Missing integration administration method: ' . $method);
    }
}

$probe = new ReflectionClass(SalesIntegrationHealthProbeInterface::class);
if (!$probe->hasMethod('probe')) {
    throw new RuntimeException('Health probe contract is missing.');
}

$catalog = new SalesIntegrationDefinitionCatalog();
$aida = $catalog->get('crm.aida');
if (($aida['provider'] ?? null) !== 'aida' || ($aida['capability'] ?? null) !== 'CRM') {
    throw new RuntimeException('AIDA CRM reference integration is invalid.');
}
$config = $catalog->normalizeConfig($aida, []);
if (($config['mode'] ?? null) !== 'native' || ($config['source_of_truth'] ?? null) !== 'aida') {
    throw new RuntimeException('AIDA CRM default integration config is invalid.');
}

echo "Sales V0.7.7 unit contract OK\n";
