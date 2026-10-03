<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$gateway = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Repository/GitHubEngineeringRepositoryGateway.php');
$schema = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/EngineeringAgentSchemas.php');

foreach ([
    "str_contains(\$path, '..')",
    "['.git/','.env','vendor/','node_modules/','var/']",
    "'force' => false",
    "'draft' => false",
    'count($changes) > 20',
] as $needle) {
    if (!str_contains($gateway, $needle)) throw new RuntimeException('Repository mutation boundary missing '.$needle);
}
foreach (["'changes'", "'CREATE','UPDATE','DELETE'", "'maxItems' => 20"] as $needle) {
    if (!str_contains($schema, $needle)) throw new RuntimeException('Developer schema missing '.$needle);
}

echo "Engineering repository mutation boundary passed.\n";
