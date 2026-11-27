<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$catalogs=[];
foreach (glob($root.'/symfony/templates/experience/unified/*.html.twig') ?: [] as $path) {
    $source=(string)file_get_contents($path);
    preg_match_all("/'((?:uw\\.)[a-z0-9_.-]+)'\\|trans(?:\\(\\s*\\{\\}\\s*,\\s*'([a-z][a-z0-9_]*)'\\s*\\))?/", $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $key=$match[1];
        $domain=$match[2]??'messages';
        foreach (['uk','en'] as $locale) {
            $file=$root.'/symfony/translations/'.$domain.'.'.$locale.'.yaml';
            $catalogs[$file]??=is_file($file)?(string)file_get_contents($file):'';
            $token=json_encode($key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).':';
            if (!str_contains($catalogs[$file],$token)) {
                throw new RuntimeException('Missing Unified locale key '.$key.' in '.$domain.'.'.$locale.' for '.basename($path));
            }
        }
    }
}
echo "Unified Workspace UK/EN template catalog keys PASS.\n";
