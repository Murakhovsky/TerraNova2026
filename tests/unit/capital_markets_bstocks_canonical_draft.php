<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\BStocksCanonicalDraftProjection;

require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $good,string $message):void {
    if (!$good) throw new RuntimeException($message);
};
$projection=new BStocksCanonicalDraftProjection();
$fixture=[
    'row'=>[
        'token_symbol'=>'AAPLB','underlying_symbol'=>'AAPL','venue_symbol'=>'AAPLBUSDT',
        'declared_instrument_type'=>'Stock','declared_leverage'=>1,
        'issuer_or_instrument_name'=>'Apple Inc.',
    ],
    'file_sha256'=>str_repeat('a',64),'source_as_of'=>'2026-09-27T17:53:00Z',
];
$x=$projection->createInput('tenant-1',$fixture);
$assert($x['status']==='DRAFT' && $x['family']==='tokenized_security',
    'Seed projection must only register inactive tokenized-security drafts.');
$assert($x['symbol']==='AAPLB' && $x['symbol']!==$x['metadata']['underlying_reference_symbol'],
    'bStock must not be silently merged with its parent equity.');
$assert($x['identifiers'][0]===['type'=>'PROVIDER_ID','source'=>'BINANCE_BSTOCKS_SEED','value'=>'AAPLB'],
    'Seed identity must carry an explicit provider namespace.');
$assert($x['metadata']['economic_link_status']==='UNVERIFIED'
    && $x['metadata']['eligible_for_execution']===false,
    'No imported workbook record may become eligible for execution.');
$assert($x['id']===$projection->createInput('tenant-1',$fixture)['id']
    && $x['id']!==$projection->createInput('tenant-2',$fixture)['id'],
    'Canonical draft identifiers must be deterministic and tenant-isolated.');
foreach ([
    ['row'=>['token_symbol'=>'AAPL','underlying_symbol'=>'AAPL','venue_symbol'=>'AAPLBUSDT']],
    ['row'=>['token_symbol'=>'AAPLB','underlying_symbol'=>'AAPL','venue_symbol'=>'AAPLBUSDT',
        'declared_instrument_type'=>'Stock','declared_leverage'=>3]],
    ['row'=>['token_symbol'=>'AAPLB','underlying_symbol'=>'AAPL','venue_symbol'=>'AAPLBUSDT',
        'declared_instrument_type'=>'ETF','declared_leverage'=>0]],
] as $mutation) {
    try {
        $projection->createInput('tenant-1',array_replace_recursive($fixture,$mutation));
        throw new RuntimeException('Unsafe seed draft accepted.');
    } catch (InvalidArgumentException) {}
}
$ref=$projection->createUnderlyingInput('tenant-1',$fixture);
$assert($ref['status']==='DRAFT' && $ref['family']==='equity'
    && $ref['symbol']==='AAPL' && $ref['id']!==$x['id'],
    'Parent reference equity must be a separate DRAFT canonical instrument.');
$assert($ref['metadata']['economic_link_status']==='UNVERIFIED'
    && $ref['metadata']['eligible_for_execution']===false,
    'Parent draft cannot become executable or economically approved.');
$assert($ref['identifiers'][0]['source']==='BINANCE_BSTOCKS_UNDERLYING_SEED',
    'Parent reference ID must not be merged into token namespace.');
$etf=$fixture;
$etf['row']['token_symbol']='QQQB';
$etf['row']['underlying_symbol']='QQQ';
$etf['row']['venue_symbol']='QQQBUSDT';
$etf['row']['declared_instrument_type']='ETF';
$etf['row']['declared_leverage']=1;
$etfReference=$projection->createUnderlyingInput('tenant-1',$etf);
$assert($etfReference['family']==='fund' && $etfReference['symbol']==='QQQ',
    'ETF reference must not be classified as a corporate equity.');
echo "Canonical DRAFT bStock projection and identity isolation passed.\n";
