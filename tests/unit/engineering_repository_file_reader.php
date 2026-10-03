<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Infrastructure\Repository\LocalRepositoryFileReader;

$root = sys_get_temp_dir().'/cos-eng-reader-'.bin2hex(random_bytes(4));
mkdir($root.'/src', 0777, true);
file_put_contents($root.'/src/Example.php', "<?php\nfinal class Example {}\n");
file_put_contents($root.'/.env', "SECRET=forbidden\n");

$reader = new LocalRepositoryFileReader($root, 20, 65536, 524288);
$files = $reader->readMany(['src/Example.php']);

if (count($files) !== 1) throw new RuntimeException('Repository file reader did not read safe file.');
if (($files[0]['complete'] ?? false) !== true) throw new RuntimeException('Small repository file must be complete.');
if (!str_contains((string) ($files[0]['content'] ?? ''), 'class Example')) throw new RuntimeException('Repository file content missing.');

try {
    $reader->readMany(['.env']);
    throw new RuntimeException('Repository file reader exposed .env.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Repository file reader exposed .env.') throw $error;
}

unlink($root.'/src/Example.php');
unlink($root.'/.env');
rmdir($root.'/src');
rmdir($root);

echo "Engineering repository file hydration passed.\n";
