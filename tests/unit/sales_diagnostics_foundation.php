<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Diagnostic\Methodology\Model\CriterionDefinition;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Sales\Diagnostics\Methodology\SalesDiagnosticCatalog;
use Domains\Sales\Diagnostics\Methodology\SalesDiagnosticDefinition;

function expectSalesDiagnostics(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$criterion = new CriterionDefinition('sales.pipeline', 'Pipeline discipline', 'section.sales', [], [], 1.0, 0.0, 0.0);
$pack = new MethodologyPack('sales-diagnostic', 1, 'Sales Diagnostic', [], [$criterion], []);
$definition = new SalesDiagnosticDefinition($pack);
$catalog = new SalesDiagnosticCatalog([$definition]);

expectSalesDiagnostics($catalog->get('sales-diagnostic', 1) === $definition, 'Sales diagnostic catalog must resolve versioned methodology.');
expectSalesDiagnostics($definition->id() === 'sales-diagnostic', 'Sales diagnostic definition must expose generic methodology id.');

echo "Sales Diagnostics foundation contract passed.\n";
