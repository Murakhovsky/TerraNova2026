<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$contracts = [
    'app/Kernel/Policy/Service/PolicyContextBuilder.php' => 'policyContextProviderFor',
    'app/Kernel/Policy/Service/ActionPolicyService.php' => 'PolicyDecision::Denied, PolicyDecision::HumanOnly => $this->actions->reject',
    'app/Infrastructure/Platform/Persistence/MySql/Configuration/MysqlSalesPolicyAdministration.php' => '$this->engine->evaluate',
    'app/Interfaces/Web/Routing/SalesRoutes.php' => '/sales/admin/actions',
    'app/Interfaces/Web/View/sales_admin/actions.phtml' => 'Test Policy',
    'app/Interfaces/Web/View/sales/admin.phtml' => 'Actions & Policies',
];
foreach ($contracts as $file => $needle) {
    $text = file_get_contents($root . '/' . $file);
    if ($text === false || !str_contains($text, $needle)) {
        throw new RuntimeException("Missing V0.7.5 contract in {$file}: {$needle}");
    }
}

$domainSource = '';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Domains/Sales'));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $domainSource .= file_get_contents($file->getPathname());
    }
}
if (str_contains($domainSource, 'class SalesPolicyEngine')) {
    throw new RuntimeException('SalesPolicyEngine duplication is forbidden.');
}
if (!str_contains($domainSource, 'PolicyContextProvidingModuleInterface')) {
    throw new RuntimeException('Sales must extend Kernel policy context through the optional module contract.');
}

echo "Sales V0.7.5 policy administration architecture: OK\n";
