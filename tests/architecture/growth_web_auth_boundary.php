<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
$authenticator = (string) file_get_contents($root . '/symfony/src/Security/SessionAuthenticator.php');
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    str_contains($security, '|growth|workspace|'),
    'Symfony firewall does not own Growth Web paths.',
);
$assert(
    str_contains($security, "path: '^/growth(?:/|$)'"),
    'Growth Web access-control rule is missing.',
);
$assert(
    str_contains($authenticator, "str_starts_with(\$path,'/growth')"),
    'SessionAuthenticator does not support Growth Web paths.',
);
$assert(
    str_contains($routes, "path: /growth\n"),
    'Canonical Growth overview route is missing.',
);

echo "Growth Web authentication boundary passed.\n";
