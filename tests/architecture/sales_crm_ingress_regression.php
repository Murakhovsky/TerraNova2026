<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'app/Interfaces/Web/Routing/FrontendRoutes.php',
    'app/Interfaces/Api/Controller/CrmWebhookController.php',
    'app/Domains/Sales/Application/UseCase/ReceiveCrmWebhook.php',
    'app/Domains/Sales/Application/UseCase/ProcessCrmInbox.php',
    'app/Domains/Sales/Automation/Job/CrmInboxJobHandler.php',
    'app/Bootstrap/SalesServices.php',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('CRM ingress regression artifact missing: ' . $path);
    }
}

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach ([
    '/api/integrations/{organization:',
    '/crm/{provider:',
    "'crm_webhook', 'receive'",
] as $needle) {
    if (!str_contains($routes, $needle)) {
        throw new RuntimeException('Existing CRM webhook route changed or disappeared: ' . $needle);
    }
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/CrmWebhookController.php');
foreach ([
    "getShared('salesReceiveCrmWebhook')",
    'X-CRM-Event-Id',
    'X-CRM-Signature',
    'getRawBody()',
    'return $this->respond(202',
] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('CRM webhook controller no longer preserves ingress contract: ' . $needle);
    }
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
if (str_contains($receiver, 'SalesIntegrationAdministrationInterface') || str_contains($controller, 'SalesIntegrationAdministrationInterface')) {
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

$handler = (string) file_get_contents($root . '/app/Domains/Sales/Automation/Job/CrmInboxJobHandler.php');
if (!str_contains($handler, 'ProcessCrmInbox') || !str_contains($handler, 'ReceiveCrmWebhook::JOB_TYPE')) {
    throw new RuntimeException('CRM inbox queue handler is no longer connected to ProcessCrmInbox through ReceiveCrmWebhook::JOB_TYPE.');
}

echo "Sales CRM ingress regression contract: OK\n";
