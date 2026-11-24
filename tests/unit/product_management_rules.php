<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\ProductManagement\Domain\ProductRules;

function pmAssert(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException($message);
}
function pmReject(callable $fn):void {
    try { $fn(); } catch (DomainException) { return; }
    throw new RuntimeException('Expected validation rejection.');
}
pmAssert(ProductRules::sku(' abc-42 ') === 'ABC-42','SKU normalization');
pmAssert(ProductRules::quantity('0.000001') === '0.000001','Exact decimal input');
pmAssert(ProductRules::money('19.9000') === '19.9000','Price precision');
pmReject(fn()=>ProductRules::sku('Ще не SKU'));
pmReject(fn()=>ProductRules::quantity('0'));
pmReject(fn()=>ProductRules::quantity('1.0000001'));
pmReject(fn()=>ProductRules::money('-1'));
pmReject(fn()=>ProductRules::productType('property'));
echo "Product Management rules passed.\n";
