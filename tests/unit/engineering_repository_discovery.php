<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Infrastructure\Repository\LocalRepositoryDiscovery;

$root = sys_get_temp_dir().'/cos-eng-discovery-'.bin2hex(random_bytes(4));
mkdir($root.'/symfony/src/Web/Experience/Async', 0777, true);
mkdir($root.'/tests/unit', 0777, true);
file_put_contents($root.'/symfony/src/Web/Experience/Async/ActivityCenterController.php', "<?php\n// Activity Center actor filter date range\n");
file_put_contents($root.'/tests/unit/other.php', "<?php\n// unrelated fixture\n");

$discovery = new LocalRepositoryDiscovery($root, 5);
$map = $discovery->discover(new EngineeringRequest('REQ-1', 'Add actor and date range filters to Activity Center.'));

if (($map->files[0]['path'] ?? '') !== 'symfony/src/Web/Experience/Async/ActivityCenterController.php') {
    throw new RuntimeException('Repository discovery did not rank the relevant file first.');
}
if ($map->repositoryRevision !== 'unknown') throw new RuntimeException('Fixture without git metadata must have unknown revision.');

unlink($root.'/symfony/src/Web/Experience/Async/ActivityCenterController.php');
unlink($root.'/tests/unit/other.php');
rmdir($root.'/symfony/src/Web/Experience/Async');
rmdir($root.'/symfony/src/Web/Experience');
rmdir($root.'/symfony/src/Web');
rmdir($root.'/symfony/src');
rmdir($root.'/symfony');
rmdir($root.'/tests/unit');
rmdir($root.'/tests');
rmdir($root);

echo "Engineering repository discovery passed.\n";
