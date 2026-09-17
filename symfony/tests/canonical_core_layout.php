<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\LegacyIdentityResolver;
use App\Infrastructure\Messenger\SymfonyCommandBus;
use App\Infrastructure\Messenger\SymfonyEventBus;
use App\Infrastructure\Messenger\SymfonyQueryBus;
use App\Security\LegacySessionAuthenticator;
use App\Security\SecurityTenantContextProvider;
use Domains\Sales\Domain\Lead\LeadId;
use Infrastructure\Platform\ReadModel\MySql\MysqlOperationsReadModel;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\EventBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Application\Command\CommandInterface;
use Kernel\Application\Event\EventInterface;
use Kernel\Application\Query\QueryInterface;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Kernel\Operations\Service\OperationsSectionReader;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Service\TenantContextFactory;

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
    CommandInterface::class => $appRoot . '/Kernel/Application/',
    QueryInterface::class => $appRoot . '/Kernel/Application/',
    EventInterface::class => $appRoot . '/Kernel/Application/',
    CommandBusInterface::class => $appRoot . '/Kernel/Application/',
    QueryBusInterface::class => $appRoot . '/Kernel/Application/',
    EventBusInterface::class => $appRoot . '/Kernel/Application/',
    OrganizationRole::class => $appRoot . '/Kernel/Identity/',
    AuthenticatedIdentity::class => $appRoot . '/Kernel/Identity/',
    IdentityResolverInterface::class => $appRoot . '/Kernel/Identity/',
    Permission::class => $appRoot . '/Kernel/Tenant/',
    TenantContext::class => $appRoot . '/Kernel/Tenant/',
    TenantContextProviderInterface::class => $appRoot . '/Kernel/Tenant/',
    TenantContextFactory::class => $appRoot . '/Kernel/Tenant/',
    OperationsReadModelInterface::class => $appRoot . '/Kernel/Operations/',
    OperationsSectionReader::class => $appRoot . '/Kernel/Operations/',
    LeadId::class => $appRoot . '/Domains/Sales/Domain/Lead/',
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

foreach ([
    LegacySessionAuthenticator::class,
    LegacyIdentityResolver::class,
    SecurityTenantContextProvider::class,
    SymfonyCommandBus::class,
    SymfonyQueryBus::class,
    SymfonyEventBus::class,
] as $adapterClass) {
    $adapterFile = (new ReflectionClass($adapterClass))->getFileName();
    expectCanonical(
        is_string($adapterFile) && str_starts_with((string) realpath($adapterFile), $symfonyRoot . '/src/'),
        sprintf('%s must remain in the Symfony composition root.', $adapterClass),
    );
}

expectCanonical(
    is_subclass_of(LegacyIdentityResolver::class, IdentityResolverInterface::class),
    'Legacy identity storage must adapt the Kernel Identity resolver contract.',
);
expectCanonical(
    is_subclass_of(SecurityTenantContextProvider::class, TenantContextProviderInterface::class),
    'Symfony Security must adapt the Kernel Tenant context provider contract.',
);
expectCanonical(
    is_subclass_of(SymfonyCommandBus::class, CommandBusInterface::class),
    'Symfony Messenger command bus must adapt the Kernel Application command bus contract.',
);
expectCanonical(
    is_subclass_of(SymfonyQueryBus::class, QueryBusInterface::class),
    'Symfony Messenger query bus must adapt the Kernel Application query bus contract.',
);
expectCanonical(
    is_subclass_of(SymfonyEventBus::class, EventBusInterface::class),
    'Symfony Messenger event bus must adapt the Kernel Application event bus contract.',
);

$organization = OrganizationId::fromString('default');
expectCanonical((string) $organization === 'default', 'Canonical Shared Kernel primitive must execute inside Symfony runtime.');
expectCanonical((string) LeadId::fromString('lead-1') === 'lead-1', 'Canonical Sales Domain primitive must execute inside Symfony runtime.');

echo "Symfony autoloads canonical COS Kernel, Platform and Domains from app/ and adapts Kernel Application/Identity/Tenant without legacy code copies.\n";
