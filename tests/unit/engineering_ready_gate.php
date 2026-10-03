<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Domain\Workflow\ReadyForHumanApprovalEvidence;
use App\Engineering\Domain\Workflow\ReadyForHumanApprovalGuard;

$guard = new ReadyForHumanApprovalGuard();
$guard->assert(new ReadyForHumanApprovalEvidence(true, true, true, true, true, true, false, false, false));

try {
    $guard->assert(new ReadyForHumanApprovalEvidence(true, true, true, true, false, true, false, false, false));
    throw new RuntimeException('READY gate accepted failed CI.');
} catch (LogicException) {
}
try {
    $guard->assert(new ReadyForHumanApprovalEvidence(true, true, true, true, true, true, true, false, false));
    throw new RuntimeException('READY gate accepted open critical finding.');
} catch (LogicException) {
}

echo "Engineering READY gate passed.\n";
