<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Application\Service\BStocksSeedFileReader;

$check=static function(bool $ok,string $reason):void {
    if (!$ok) throw new RuntimeException($reason);
};
$reader=new BStocksSeedFileReader();
$base=dirname(__DIR__,2);
$seed=$reader->read($base.'/resources/capital-markets/universes/binance-bstocks-2026-09-27.csv');
$check(count($seed['rows'])===80,'CSV seed must contain 80 instruments.');
$check(count(array_filter($seed['rows'],static fn(array $row):bool=>$row['risk_class']==='LEVERAGED_OR_INVERSE'))===9,
    'Leveraged and inverse ETFs must remain isolated.');
$check($seed['rows'][1]['token_symbol']==='AAPLB','Historical token identity changed.');
$csv=tempnam(sys_get_temp_dir(),'cm-bstocks-').'.csv';
file_put_contents($csv, "underlying_symbol,token_symbol,binance_spot_symbol,source_asset_type,leverage_factor\nAAPL,AAPLB,AAPLBUSDT,Stock,1\nAAPL,AAPLB,AAPLBUSDT,Stock,1\n");
try {
    try { $reader->read($csv); throw new RuntimeException('Duplicate rows accepted.'); }
    catch (InvalidArgumentException) {}
} finally { @unlink($csv); }
if (class_exists(ZipArchive::class) && class_exists(DOMDocument::class)) {
    $file=tempnam(sys_get_temp_dir(),'cm-bstocks-').'.xlsm';
    $zip=new ZipArchive();
    $check($zip->open($file,ZipArchive::CREATE | ZipArchive::OVERWRITE)===true,'Fixture ZIP creation failed.');
    $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $zip->addFromString('xl/sharedStrings.xml','<?xml version="1.0"?><sst xmlns="'.$ns.'"><si><t>AAPL</t></si><si><t>AAPLB</t></si><si><t>AAPLBUSDT</t></si><si><t>TRADING</t></si><si><t>https://www.binance.com/en/trade/AAPLB_USDT</t></si><si><t>Apple Inc.</t></si><si><t>Stock</t></si><si><t>name</t></si><si><t>type</t></si><si><t>leverage</t></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0"?><worksheet xmlns="'.$ns.'"><sheetData><row r="2"><c r="A2" t="inlineStr"><is><t>Станом на 2026-09-27 17:53 UTC</t></is></c></row><row r="3"><c r="G3" t="s"><v>7</v></c><c r="H3" t="s"><v>8</v></c><c r="I3" t="s"><v>9</v></c></row><row r="4"><c r="B4" t="s"><v>0</v></c><c r="C4" t="s"><v>1</v></c><c r="D4" t="s"><v>2</v></c><c r="E4" t="s"><v>3</v></c><c r="F4" t="s"><v>4</v></c><c r="G4" t="s"><v>5</v></c><c r="H4" t="s"><v>6</v></c><c r="I4"><v>1</v></c></row></sheetData></worksheet>');
    $zip->close();
    try {
        $parsed=$reader->read($file);
        $check(count($parsed['rows'])===1,'OOXML row count wrong.');
        $check($parsed['rows'][0]['issuer_or_instrument_name']==='Apple Inc.','OOXML strings not decoded.');
        $check($parsed['rows'][0]['declared_status']==='TRADING','OOXML status lost.');
        $check($parsed['source_as_of']==='2026-09-27T17:53:00Z','OOXML timestamp missing.');
    } finally { @unlink($file); }
}
echo "Safe bStocks CSV/OOXML seed validation passed.\n";
