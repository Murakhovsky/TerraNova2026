<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'app/Domains/Sales/Application/Contract/CrmIngressResolverInterface.php',
    'app/Infrastructure/Integration/Crm/MysqlCrmIngressResolver.php',
    'app/Interfaces/Web/Routing/FrontendRoutes.php',
    'app/Interfaces/Web/Routing/SalesIntegrationRoutes.php',
    'app/Interfaces/Api/Controller/CrmWebhookController.php',
    'app/Domains/Sales/Application/UseCase/ReceiveCrmWebhook.php',
    'app/Domains/Sales/Application/UseCase/ProcessCrmInbox.php',
    'app/Domains/Sales/Automation/Job/CrmInboxJobHandler.php',
    'app/Bootstrap/SalesServices.php',
    'app/Bootstrap/SalesIntegrationServices.php',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('CRM ingress regression artifact missing: ' . $path);
    }
}

$legacyRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach ([
    '/api/integrations/{organization:',
    '/crm/{provider:',
    "'crm_webhook', 'receive'",
] as $needle) {
    if (!str_contains($legacyRoutes, $needle)) {
        throw new RuntimeException('Legacy CRM webhook compatibility route changed or disappeared: ' . $needle);
    }
}

$integrationRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SalesIntegrationRoutes.php');
foreach ([
    '/api/integrations/crm/{integration:[0-9]+}/webhook',
    "'controller' => 'crm_webhook'",
    "'action' => 'receiveIntegration'",
] as $needle) {
    if (!str_contains($integrationRoutes, $needle)) {
        throw new RuntimeException('Canonical Integration Control Plane CRM route is missing: ' . $needle);
    }
}

$resolverContract = (string) file_get_contents($root . '/app/Domains/Sales/Application/Contract/CrmIngressResolverInterface.php');
if (!str_contains($resolverContract, 'interface CrmIngressResolverInterface')
    || !str_contains($resolverContract, 'public function resolve(int $integrationId): array;')
) {
    throw new RuntimeException('CRM ingress resolver contract is incomplete.');
}

$resolver = (string) file_get_contents($root . '/app/Infrastructure/Integration/Crm/MysqlCrmIngressResolver.php');
foreach ([
    'CrmIngressResolverInterface',
    'FROM cos_integrations',
    'id=:id',
    'capability="CRM"',
    'status="ACTIVE"',
    "'organization_id' =>",
    "'provider' =>",
] as $needle) {
    if (!str_contains($resolver, $needle)) {
        throw new RuntimeException('CRM ingress resolver no longer uses the active Integration Control Plane record: ' . $needle);
    }
}
foreach (['credentials_reference', 'config,', 'SELECT *'] as $forbidden) {
    if (str_contains($resolver, $forbidden)) {
        throw new RuntimeException('CRM ingress endpoint resolver must not read integration secrets/configuration: ' . $forbidden);
    }
}
if (preg_match('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\b/i', $resolver)) {
    throw new RuntimeException('CRM ingress resolver must remain read-only.');
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/CrmWebhookController.php');
foreach ([
    'CrmIngressResolverInterface',
    "getShared('salesCrmIngressResolver')",
    'resolve((int) $integrationId)',
    'return $this->receiveAction(',
    "getShared('salesReceiveCrmWebhook')",
    'X-CRM-Event-Id',
    'X-CRM-Signature',
    'getRawBody()',
    'return $this->respond(202',
] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('CRM webhook controller no longer preserves canonical ingress contract: ' . $needle);
    }
}
if (str_contains($controller, 'SalesIntegrationAdministrationInterface')) {
    throw new RuntimeException('Integration administration must not become the CRM webhook ingress runtime.');
}

$receiver = (string) file_get_contents($root . '/app/Domains/Sales/Application/UseCase/ReceiveCrmWebhook.php');
foreach ([
    'CrmWebhookSecretResolverInterface',
    'CrmInboxRepositoryInterface',
    'JobQueueInterface',
    'TransactionManagerInterface',
    "public const JOB_TYPE = 'CRM_INBOX_PROCESS'",
    "hash_hmac('sha256'",
    'hash_equals(',
    '1_048_576',
    "'crm-inbox:' . $organizationId . ':' . $provider . ':' . $externalEventId",
] as $needle) {
    if (!str_contains($receiver, $needle)) {
        throw new RuntimeException('CRM ingress security/inbox/queue contract changed: ' . $needle);
    }
}
if (str_contains($receiver, 'SalesIntegrationAdministrationInterface')) {
    throw new RuntimeException('Integration administration must not become the CRM webhook ingress runtime.');
}

$transaction = strpos($receiver, '$this->transactions->transactional');
$receive = strpos($receiver, '$this->inbox->receive');
$enqueue = strpos($receiver, '$this->queue->enqueue');
if ($transaction === false || $receive === false || $enqueue === false || !($transaction < $receive && $receive < $enqueue)) {
    throw new RuntimeException('CRM webhook must persist inbox and enqueue processing inside the transaction in the established order.');
}

$services = (string) file_get_contents($root . '/app/Bootstrap/SalesServices.php');
foreach ([
    "setShared('salesReceiveCrmWebhook'",
    "getShared('cosCrmWebhookSecrets')",
    "getShared('cosCrmInbox')",
    "getShared('cosJobQueue')",
    "getShared('cosTransactionManager')",
    "setShared('salesProcessCrmInbox'",
    "setShared('salesCrmInboxJobHandler'",
] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('CRM ingress composition wiring missing: ' . $needle);
    }
}

$integrationServices = (string) file_get_contents($root . '/app/Bootstrap/SalesIntegrationServices.php');
foreach ([
    'MysqlCrmIngressResolver',
    "setShared('salesCrmIngressResolver'",
    "getShared('databaseService')->connection()",
] as $needle) {
    if (!str_contains($integrationServices, $needle)) {
        throw new RuntimeException('Control Plane CRM ingress resolver wiring missing: ' . $needle);
    }
}

$handler = (string) file_get_contents($root . '/app/Domains/Sales/Automation/Job/CrmInboxJobHandler.php');
if (!str_contains($handler, 'ProcessCrmInbox') || !str_contains($handler, 'ReceiveCrmWebhook::JOB_TYPE')) {
    throw new RuntimeException('CRM inbox queue handler is no longer connected to ProcessCrmInbox through ReceiveCrmWebhook::JOB_TYPE.');
}

echo "Sales CRM ingress regression contract: OK\n";
