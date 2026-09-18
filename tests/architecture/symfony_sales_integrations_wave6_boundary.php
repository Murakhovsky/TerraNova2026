<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    if (!is_file($file)) {
        throw new RuntimeException('Missing Wave 6 file: ' . $path);
    }
    return (string) file_get_contents($file);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$routes = $read('symfony/config/routes.yaml');
foreach ([
    '/api/v1/sales/integrations/catalog',
    '/api/v1/sales/integrations',
    '/api/v1/sales/integrations/routing-options',
    '/api/v1/sales/integrations/{id}',
    '/api/v1/sales/integrations/{id}/test',
    '/api/v1/sales/integrations/{id}/routes',
    '/api/v1/sales/integrations/{id}/revisions',
    '/api/v1/integrations/crm/{id}/webhook',
    '/api/v1/sales/opportunities/{id}/communications',
] as $route) {
    $assert(str_contains($routes, $route), 'Missing Wave 6 route: ' . $route);
}

$security = $read('symfony/config/packages/security.yaml');
$authenticator = $read('symfony/src/Security/LegacySessionAuthenticator.php');
$assert(str_contains($security, "integrations/crm/[1-9][0-9]*/webhook"), 'Signed CRM webhook public access rule is missing.');
$assert(str_contains($security, 'PUBLIC_ACCESS'), 'Signed CRM webhook must not require a legacy browser session.');
$assert(str_contains($authenticator, 'integrations/crm/[1-9][0-9]*/webhook'), 'Legacy session authenticator must exclude signed CRM webhook.');

$integrationController = $read('symfony/src/Http/Api/V1/Controller/SalesIntegrationController.php');
foreach ([
    'CommandBusInterface',
    'QueryBusInterface',
    'TenantContextProviderInterface',
    'LegacySessionCsrfValidator',
    'ActiveModuleResolver',
    'TenantPermissions::MANAGE',
    'X-Idempotency-Key',
    '_cos_correlation_id',
] as $needle) {
    $assert(str_contains($integrationController, $needle), 'Integration controller boundary missing: ' . $needle);
}
$assert(!str_contains($integrationController, 'PDO'), 'Integration controller must not access PDO.');
$assert(!str_contains($integrationController, 'Domains\\'), 'Integration controller must not depend on Domains directly.');

$communicationController = $read('symfony/src/Http/Api/V1/Controller/SalesCommunicationController.php');
foreach (['CommandBusInterface', 'TenantContextProviderInterface', 'LegacySessionCsrfValidator', 'X-Idempotency-Key'] as $needle) {
    $assert(str_contains($communicationController, $needle), 'Communication controller boundary missing: ' . $needle);
}
$assert(!str_contains($communicationController, 'PDO'), 'Communication controller must not access PDO.');
$assert(!str_contains($communicationController, 'Domains\\'), 'Communication controller must not depend on Domains directly.');

$webhookHandler = $read('symfony/src/Application/Integration/Command/ReceiveCrmWebhookCommandHandler.php');
foreach ([
    'CrmIngressResolverInterface',
    'CrmWebhookCredentialResolverInterface',
    'CrmInboxRepositoryInterface',
    "hash_hmac('sha256'",
    'hash_equals',
    'ProcessCrmInboxCommand',
    'crm.webhook.accepted',
] as $needle) {
    $assert(str_contains($webhookHandler, $needle), 'Webhook ingress guarantee missing: ' . $needle);
}
$assert(str_contains($webhookHandler, 'secretFor($command->integrationId'), 'Webhook secret must be scoped to integration id.');

$credentialResolver = $read('symfony/src/Infrastructure/Integration/MysqlCrmWebhookCredentialResolver.php');
foreach (['id=:id', 'organization_id=:organization_id', 'provider=:provider', 'status="ACTIVE"', 'env:'] as $needle) {
    $assert(str_contains($credentialResolver, $needle), 'Integration-scoped credential lookup missing: ' . $needle);
}

$inbox = $read('app/Infrastructure/Integration/Crm/MysqlCrmInboxRepository.php');
foreach ([
    'CrmInboxPendingRepositoryInterface',
    'payload_hash',
    'CRM external event id was reused with a different payload.',
    "status IN ('RECEIVED','FAILED')",
    'public function pending',
] as $needle) {
    $assert(str_contains($inbox, $needle), 'CRM inbox reliability contract missing: ' . $needle);
}

$adminHandler = $read('symfony/src/Application/Integration/Command/ManageSalesIntegrationCommandHandler.php');
foreach ([
    'SalesMutationReceiptRepositoryInterface',
    'TransactionManagerInterface',
    'IntegrationMutationAudit',
    'payloadHash',
    'Idempotency key was reused with a different integration mutation payload.',
    '->transactional(',
] as $needle) {
    $assert(str_contains($adminHandler, $needle), 'Integration admin write guarantee missing: ' . $needle);
}

$sendHandler = $read('symfony/src/Application/Integration/Command/SendSalesCommunicationCommandHandler.php');
foreach ([
    'SalesOperationService',
    'CommunicationChannel::values()',
    'SalesMutationReceiptRepositoryInterface',
    'different communication payload',
    'TransactionManagerInterface',
    'sales.communication.sent',
] as $needle) {
    $assert(str_contains($sendHandler, $needle), 'Communication reliability guarantee missing: ' . $needle);
}

$messenger = $read('symfony/config/packages/messenger.yaml');
foreach (['ProcessCrmInboxCommand', 'SweepCrmInboxCommand', 'SendSalesCommunicationCommand'] as $needle) {
    $assert(str_contains($messenger, $needle), 'Wave 6 async route missing: ' . $needle);
}

$scheduler = $read('symfony/src/Scheduler/CosScheduleProvider.php');
$assert(str_contains($scheduler, 'SweepCrmInboxCommand'), 'CRM inbox recovery sweep is not scheduled.');
$assert(str_contains($scheduler, "'1 minute'"), 'CRM inbox recovery sweep must run every minute.');

$services = $read('symfony/config/services.yaml');
foreach ([
    'SalesIntegrationAdministrationInterface',
    'CrmInboxRepositoryInterface',
    'CrmInboxPendingRepositoryInterface',
    'CrmIngressResolverInterface',
    'CrmInboundApplierInterface',
    'CrmWebhookCredentialResolverInterface',
    'ProcessCrmInbox',
] as $needle) {
    $assert(str_contains($services, $needle), 'Wave 6 Symfony wiring missing: ' . $needle);
}

echo "Symfony Sales Communications / Integrations Wave 6 architecture boundary OK\n";
