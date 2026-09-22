<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Web\Experience\Extension\ProviderBackedShellNavigation;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
use App\Web\Sales\SalesWorkspaceController;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Application\Query\QueryInterface;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

function expectSalesAccess(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @template T of object @param class-string<T> $class @return T */
function withoutConstructor(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

$queries = new class implements QueryBusInterface {
    public int $calls = 0;

    public function ask(QueryInterface $query): mixed
    {
        $this->calls++;
        throw new RuntimeException('QueryBus must not run for rejected Sales access.');
    }
};

$controllerFor = static function (?TenantContext $context) use ($queries): SalesWorkspaceController {
    $tenants = new class($context) implements TenantContextProviderInterface {
        public function __construct(private readonly ?TenantContext $context) {}
        public function current(): ?TenantContext { return $this->context; }
    };

    return new SalesWorkspaceController(
        withoutConstructor(Environment::class),
        $queries,
        $tenants,
        withoutConstructor(ProviderBackedShellNavigation::class),
        withoutConstructor(WorkspaceCompositionResolver::class),
    );
};

$anonymous = $controllerFor(null)->dashboard();
expectSalesAccess($anonymous instanceof RedirectResponse, 'Anonymous Sales access must redirect.');
expectSalesAccess($anonymous->getTargetUrl() === '/auth/login', 'Anonymous Sales access must redirect to canonical login.');
expectSalesAccess($queries->calls === 0, 'Anonymous Sales access must not hit QueryBus.');

$employee = new TenantContext(
    UserId::fromString('42'),
    OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('employee'),
);
$forbidden = $controllerFor($employee)->dashboard();
expectSalesAccess($forbidden instanceof Response && $forbidden->getStatusCode() === 403, 'Non-manager Sales access must return 403.');
expectSalesAccess($queries->calls === 0, 'Forbidden Sales access must not hit QueryBus.');

echo "Wave 12.26 Sales access contract passed.\n";
