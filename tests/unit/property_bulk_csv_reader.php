<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $name)use($root):void {
    if(!str_starts_with($name,'App\\'))return;
    $file=$root.'/symfony/src/'.str_replace('\\','/',substr($name,4)).'.php';
    if(is_file($file))require $file;
});
use App\Application\Property\PropertyBulkCsvReader;
$reader=new PropertyBulkCsvReader();
$check=static function(bool $yes,string $reason):void {
    if(!$yes)throw new RuntimeException($reason);
};
$reject=static function(string $name,string $text)use($reader):void {
    try{$reader->parse($name,$text);}
    catch(DomainException){return;}
    throw new RuntimeException('Unsafe CSV was accepted: '.$name);
};
$csv="\xEF\xBB\xBFtype_code;city;address;area_total;rooms\nhouse;Львів;Лісова 12;120,5;4\napartment;Львів;Лісова 14;45;2\n";
$rows=$reader->parse('properties.csv',$csv);
$check(count($rows)===2 && $rows[0]['area_total']===120.5
    && $rows[1]['rooms']===2.0 && $rows[0]['address']==='Лісова 12',
    'Semicolon/BOM/decimal import failed.');
$tab=$reader->parse('list.CSV',"type_code\tcity\taddress\nhouse\tЛьвів\tКвіткова 1\n");
$check(count($tab)===1,'TSV-style Excel CSV delimiter failed.');
$reject('evil.xlsm',$csv);
$reject('test.csv',"type_code,city,address\nhouse,Львів,=HYPERLINK(1)\n");
$reject('test.csv',"type_code,type_code,city,address\nhouse,house,Львів,Х 1\n");
$reject('test.csv',"type_code,city,address,unsupported\nhouse,Львів,Х 1,something\n");
$reject('test.csv',"type_code,city,address,area_total\nhouse,Львів,Х 1,-2\n");
$reject('test.csv',"type_code,city,address\nhouse,Львів\n");
$reject('test.csv',str_repeat('x',65537));
$reject('test.csv',"type_code,city,address\n".str_repeat("house,Львів,Нова 1\n",11));
$reject('test.csv',"type_code,city,address\nhouse,\xFF,Нова 1\n");
echo "Property Bulk CSV reader PASS.\n";
