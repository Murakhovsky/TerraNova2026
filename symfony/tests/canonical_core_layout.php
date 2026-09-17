<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Security\LegacySessionAuthenticator;
use Infrastructure\Platform\ReadModel\MySql\MysqlOperationsReadModel;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Kernel\Operations\Service\OperationsSectionReader;
use Kernel\Shared\Domain\OrganizationId;

function expectCanonical(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$symfonyRoot = realpath(dirname(__DIR__));
$projectRoot = realpath(dirname(__DIR__, 2));
$appRoot = $projectRoot !== false ? realpath($projectRoot . '/app') : false;

expectCanonical($symfonyRoot !== false, 'Symfony root must resolve.');
expectCanonical($appRoot !== false, 'Canonical root app/ must exist next to Symfony composition root.');

$canonicalClasses = [
    OrganizationId::class => $appRoot . '/Kernel/Shared/',
    OperationsReadModelInterface::class => $appRoot . '/Kernel/Operations/',
    OperationsSectionReader::class => $appRoot . '/Kernel/Operations/',
    MysqlOperationsReadModel::class => $appRoot . '/Infrastructure/',
];

foreach ($canonicalClasses as $class => $expectedPrefix) {
    expectCanonical(class_exists($class) || interface_exists($class), sprintf('%s must autoload.', $class));

    $file = (new ReflectionClass($class))->getFileName();
    expectCanonical(is_string($file) && $file !== '', sprintf('%s must have a source file.', $class));

    $resolved = realpath($file);
    expectCanonical($resolved !== false, sprintf('%s source file must resolve.', $class));
    expectCanonical(str_starts_with($resolved, $expectedPrefix), sprintf('%s must load from canonical app/, got %s.', $class, $resolved));
    expectCanonical(!str_contains($resolved, '/legacy/'), sprintf('%s must not load from a legacy copy.', $class));
}

$appClass = new ReflectionClass(LegacySessionAuthenticator::class);
$appFile = $appClass->getFileName();
expectCanonical(is_string($appFile) && str_starts_with((string) realpath($appFile), $symfonyRoot . '/src/'), 'Symfony adapters must remain in the Symfony composition root.');

$organization = OrganizationId::fromString('default');
expectCanonical((string) $organization === 'default', 'Canonical Shared Kernel primitive must execute inside Symfony runtime.');

echo "Symfony autoloads canonical COS app/ without legacy code copies.\n";
