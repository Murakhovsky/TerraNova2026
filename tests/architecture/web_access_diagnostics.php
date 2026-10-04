<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$subscriber = (string) file_get_contents($root.'/symfony/src/Web/Experience/Security/WebAccessDeniedResponseSubscriber.php');
foreach ([
    'KernelEvents::RESPONSE',
    "Response::HTTP_FORBIDDEN",
    "trim((string) \$response->getContent()) === 'Forbidden'",
    "['GET', 'HEAD']",
    "experience/security/access_denied.html.twig",
] as $needle) {
    if (!str_contains($subscriber, $needle)) {
        throw new RuntimeException('Access denied responder contract missing: '.$needle);
    }
}

$controller = (string) file_get_contents($root.'/symfony/src/Web/Account/AccountAccessController.php');
foreach ([
    'TenantContextProviderInterface',
    'TenantPermissions::all()',
    'permissions',
    "experience/account/access.html.twig",
] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('Account access diagnostics contract missing: '.$needle);
    }
}

$routes = (string) file_get_contents($root.'/symfony/config/routes.yaml');
foreach (['cos_web_account_access:', 'path: /account/access', 'AccountAccessController'] as $needle) {
    if (!str_contains($routes, $needle)) {
        throw new RuntimeException('Account access route missing: '.$needle);
    }
}

$security = (string) file_get_contents($root.'/symfony/config/packages/security.yaml');
if (!str_contains($security, "path: '^/account(?:/|$)'")) {
    throw new RuntimeException('Account diagnostics are not protected by authenticated access.');
}

$authenticator = (string) file_get_contents($root.'/symfony/src/Security/SessionAuthenticator.php');
if (!str_contains($authenticator, "str_starts_with(\$path,'/account')")) {
    throw new RuntimeException('SessionAuthenticator does not own the account diagnostics path.');
}

$navigation = (string) file_get_contents($root.'/symfony/src/Web/Experience/Extension/ProviderBackedShellNavigation.php');
if (!str_contains($navigation, "'/account/access'")) {
    throw new RuntimeException('Account access diagnostics are missing from utility navigation.');
}

echo "Web access diagnostics contract passed.\n";
