<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$uk=(string)file_get_contents($root.'/symfony/translations/messages.uk.yaml');
$en=(string)file_get_contents($root.'/symfony/translations/messages.en.yaml');
foreach (glob($root.'/symfony/templates/experience/unified/*.html.twig') ?: [] as $path) {
    $source=(string)file_get_contents($path);
    preg_match_all("/'((?:uw\\.)[a-z0-9_.-]+)'\\|trans/", $source, $matches);
    foreach ($matches[1] as $key) {
        $token=json_encode($key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).':';
        if (!str_contains($uk,$token)||!str_contains($en,$token)) {
            throw new RuntimeException('Missing Unified locale key '.$key.' in '.basename($path));
        }
    }
}
echo "Unified Workspace UK/EN template catalog keys PASS.\n";
