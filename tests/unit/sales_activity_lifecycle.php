<?php
declare(strict_types=1);

use Domains\Sales\Domain\Policy\PropertyMatchLifecycle;
require dirname(__DIR__,2).'/vendor/autoload.php';
$check=static function(bool $ok):void{if(!$ok)throw new RuntimeException('Property match lifecycle invariant failed.');};
$check(PropertyMatchLifecycle::allows(null,'suggested'));
$check(PropertyMatchLifecycle::allows(null,'interested'));
$check(!PropertyMatchLifecycle::allows(null,'deal'));
$check(PropertyMatchLifecycle::allows('suggested','sent'));
$check(PropertyMatchLifecycle::allows('sent','interested'));
$check(PropertyMatchLifecycle::allows('interested','viewing'));
$check(!PropertyMatchLifecycle::allows('rejected','sent'));
$check(!PropertyMatchLifecycle::allows('deal','suggested'));
echo "Sales property match lifecycle PASS.\n";
