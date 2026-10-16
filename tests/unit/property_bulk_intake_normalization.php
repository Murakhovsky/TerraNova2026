<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($root):void {
    if (!str_starts_with($class,'App\\')) return;
    $file=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if (is_file($file)) require $file;
});
use App\Persistence\Property\PropertyBulkIntakeService;
$valid=PropertyBulkIntakeService::canonicalize([
    'type_code'=>' house ','city'=>' Львів ','address'=>' Шевченка, 12 ',
    'area_total'=>120.5
]);
if ($valid['type_code']!=='house' || $valid['city']!=='Львів'
    || $valid['address']!=='Шевченка, 12' || $valid['area_total']!==120.5) {
    throw new RuntimeException('Canonical Property input normalization failed.');
}
foreach([
    ['type_code'=>'house','city'=>'Львів','address'=>'Х, 1','tenant_id'=>'evil'],
    ['type_code'=>'house','city'=>'Львів','address'=>'Х, 1','area_total'=>'120.5'],
    ['type_code'=>'house','city'=>'Львів','address'=>'Х, 1','area_total'=>-1],
    ['type_code'=>'house','city'=>'Львів','address'=>'Х, 1','asset_id'=>'forged'],
    ['type_code'=>'house','city'=>'Львів'],
] as $case) {
    try { PropertyBulkIntakeService::canonicalize($case); }
    catch(DomainException) { continue; }
    throw new RuntimeException('Unsafe bulk row was accepted: '.json_encode($case));
}
echo "Property bulk normalization PASS.\n";
