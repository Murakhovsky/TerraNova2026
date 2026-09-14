<?php
declare(strict_types=1);

use Kernel\Module\ModuleDiscovery;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/cos-module-discovery-' . bin2hex(random_bytes(6));
$domains = $root . '/Domains';
$moduleDir = $domains . '/Example';
$cache = $root . '/cache/cos_modules.php';
if (!mkdir($moduleDir, 0775, true) && !is_dir($moduleDir)) {
    throw new RuntimeException('Unable to create module discovery fixture.');
}

$moduleFile = $moduleDir . '/module.php';
$writeModule = static function (string $version) use ($moduleFile): void {
    $source = <<<'PHP'
<?php
$GLOBALS['cos_module_source_loads'] = ($GLOBALS['cos_module_source_loads'] ?? 0) + 1;
return [
    'id' => 'example',
    'name' => 'Example',
    'version' => '__VERSION__',
    'schema_version' => '__VERSION__',
    'kernel_constraint' => '*',
    'enabled_by_default' => true,
];
PHP;
    file_put_contents($moduleFile, str_replace('__VERSION__', $version, $source));
};

try {
    $GLOBALS['cos_module_source_loads'] = 0;
    $writeModule('1.0.0');
    $discovery = new ModuleDiscovery($domains, $cache);

    $first = $discovery->discover();
    if (($GLOBALS['cos_module_source_loads'] ?? 0) !== 1 || !is_file($cache)) {
        throw new RuntimeException('Initial module discovery must load source once and compile the cache.');
    }
    if (($first[0]->manifest->version ?? null) !== '1.0.0') {
        throw new RuntimeException('Initial module discovery returned an unexpected version.');
    }

    $second = $discovery->discover();
    if (($GLOBALS['cos_module_source_loads'] ?? 0) !== 1) {
        throw new RuntimeException('Fresh compiled module cache must avoid re-requiring module.php.');
    }
    if (($second[0]->manifest->version ?? null) !== '1.0.0') {
        throw new RuntimeException('Compiled module cache changed the module definition.');
    }

    $writeModule('1.0.1');
    touch($moduleFile, time() + 2);
    clearstatcache(true, $moduleFile);
    $third = $discovery->discover();
    if (($GLOBALS['cos_module_source_loads'] ?? 0) !== 2) {
        throw new RuntimeException('Changed module source must invalidate the compiled cache.');
    }
    if (($third[0]->manifest->version ?? null) !== '1.0.1') {
        throw new RuntimeException('Invalidated module cache did not expose the new definition.');
    }
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $target = $path . '/' . $item;
            if (is_dir($target)) $remove($target); else @unlink($target);
        }
        @rmdir($path);
    };
    $remove($root);
}

echo "COS module discovery cache invariant passed.\n";
