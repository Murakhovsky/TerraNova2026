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
    'app/Domains/Sales/Automation/Job/CrmInboxJobHandler.php',
    'app/Bootstrap/SalesServices.php',
    'app/Bootstrap/SalesIntegrationServices.php',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('CRM ingress artifact missing: ' . $path);
    }
}

$legacyRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach (['/api/integrations/{organization:', '/crm/{provider:', 'crm_webhook', 'receive'] as $needle) {
    if (!str_contains($legacyRoutes, $needle)) {
        throw new RuntimeException('Legacy CRM ingress compatibility route missing: ' . $needle);
    }
}

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SalesIntegrationRoutes.php');
foreach (['/api/integrations/crm/{integration:', 'crm_webhook', 'receiveIntegration'] as $needle) {
    if (!str_contains($routes, $needle)) {
        throw new RuntimeException('Canonical control-plane CRM ingress route missing: ' . $needle);
    }
}

$resolver = (string) file_get_contents($root . '/app/Infrastructure/Integration/Crm/MysqlCrmIngressResolver.php');
foreach (['CrmIngressResolverInterface', 'cos_integrations', 'capability', 'CRM', 'status', 'ACTIVE', 'organization_id', 'provider'] as $needle) {
    if (!str_contains($resolver, $needle)) {
        throw new RuntimeException('CRM ingress resolver missing control-plane contract: ' . $needle);
    }
}
foreach (['credentials_reference', 'SELECT *'] as $forbidden) {
    if (str_contains($resolver, $forbidden)) {
        throw new RuntimeException('CRM ingress resolver crossed secret/config boundary: ' . $forbidden);
    }
}
if (preg_match('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\b/i', $resolver)) {
    throw new RuntimeException('CRM ingress resolver must remain read-only.');
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/CrmWebhookController.php');
foreach (['salesCrmIngressResolver', 'resolve((int) $integrationId)', 'receiveAction(', 'salesReceiveCrmWebhook', 'X-CRM-Event-Id', 'X-CRM-Signature', 'getRawBody()'] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('CRM webhook controller missing runtime boundary: ' . $needle);
    }
}

$receiver = (string) file_get_contents($root . '/app/Domains/Sales/Application/UseCase/ReceiveCrmWebhook.php');
foreach (['CrmWebhookSecretResolverInterface', 'hash_hmac', 'hash_equals', '1_048_576', 'crm-inbox:', 'CRM_INBOX_PROCESS'] as $needle) {
    if (!str_contains($receiver, $needle)) {
        throw new RuntimeException('Established CRM security/queue contract changed: ' . $needle);
    }
}
$transaction = strpos($receiver, '$this->transactions->transactional');
$receive = strpos($receiver, '$this->inbox->receive');
$enqueue = strpos($receiver, '$this->queue->enqueue');
if ($transaction === false || $receive === false || $enqueue === false || !($transaction < $receive && $receive < $enqueue)) {
    throw new RuntimeException('CRM inbox persistence and queueing must remain transactional and ordered.');
}

$services = (string) file_get_contents($root . '/app/Bootstrap/SalesServices.php');
foreach (['salesReceiveCrmWebhook', 'cosCrmWebhookSecrets', 'cosCrmInbox', 'cosJobQueue', 'salesProcessCrmInbox', 'salesCrmInboxJobHandler'] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Established CRM ingress wiring missing: ' . $needle);
    }
}
$integrationServices = (string) file_get_contents($root . '/app/Bootstrap/SalesIntegrationServices.php');
foreach (['salesCrmIngressResolver', 'MysqlCrmIngressResolver', 'databaseService'] as $needle) {
    if (!str_contains($integrationServices, $needle)) {
        throw new RuntimeException('Control-plane CRM ingress wiring missing: ' . $needle);
    }
}

$handler = (string) file_get_contents($root . '/app/Domains/Sales/Automation/Job/CrmInboxJobHandler.php');
if (!str_contains($handler, 'ProcessCrmInbox') || !str_contains($handler, 'ReceiveCrmWebhook::JOB_TYPE')) {
    throw new RuntimeException('CRM inbox worker is disconnected from the established job type.');
}

echo "Sales CRM ingress control-plane regression: OK\n";
