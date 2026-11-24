<?php
declare(strict_types=1);

/** Contract check for SERIAL SERIES01 marketing concepts. No Shopify/COS mutations. */
$root=dirname(__DIR__,2);
$manifest=json_decode(
    (string) file_get_contents($root.'/resources/pilots/series01/product-market-manifest.json'),
    true, 512, JSON_THROW_ON_ERROR
);
$csv=(string)file_get_contents($root.'/resources/pilots/series01/approved-import-candidate.csv');
$lines=array_values(array_filter(preg_split('/\r?\n/',$csv) ?: []));
$header=array_map('trim',str_getcsv(array_shift($lines) ?: ''));
if ($header!==['sku','name','type','uom','track_inventory']) {
    throw new RuntimeException('SERIES01 CSV must match Product Management import contract.');
}
$products=$manifest['products'] ?? [];
if (count($products)!==9 || count($lines)!==9) {
    throw new RuntimeException('SERIES01 must contain exactly nine distinct candidate SKUs.');
}
if (($manifest['shop_domain'] ?? '')!=='e2rnbw-mr.myshopify.com'
    || ($manifest['canonical_owner'] ?? '')!=='product_management'
    || ($manifest['experiment_owner'] ?? '')!=='growth') {
    throw new RuntimeException('Wrong Shopify store or domain owner.');
}
$rules=$manifest['commercial_rules'] ?? [];
if (($rules['standard_unit_floor_usd'] ?? 0)!==300
    || ($rules['custom_min_qty'] ?? 0)!==10
    || ($rules['custom_min_unit_price_usd'] ?? 0)!==1000) {
    throw new RuntimeException('SERIES01 business constraints changed unexpectedly.');
}
$guards=$manifest['guardrails'] ?? [];
foreach (['concept_only','no_checkout','no_inventory_claims','no_auto_publish'] as $guard) {
    if (($guards[$guard]??null)!==true) {
        throw new RuntimeException('Concept product guardrail missing: '.$guard);
    }
}
$seen=[];
foreach ($products as $index=>$product) {
    $fields=str_getcsv($lines[$index]);
    if (count($fields)!==5 || $fields[2]!=='stock' || $fields[3]!=='pcs'
        || $fields[4]!=='false' || $fields[0]!==($product['sku']??null)
        || $fields[1]!==($product['title']??null)) {
        throw new RuntimeException('Import/manifest mismatch at product '.($index+1));
    }
    $sku=$fields[0];
    if (isset($seen[$sku]) || !preg_match('/^SERIES01-[LFA]0[1-3]$/D',$sku)) {
        throw new RuntimeException('Duplicate or unsupported series SKU.');
    }
    $seen[$sku]=true;
    if (($product['concept_price_usd']??0)<300
        || ($product['price_is_verified']??null)!==false
        || ($product['ready_for_sale']??null)!==false
        || ($product['publication_status']??null)!=='BLOCKED'
        || ($product['image_type']??null)!=='AI_CONCEPT_NOT_PHOTO') {
        throw new RuntimeException('Concept safety/price invariant failed: '.$sku);
    }
}
$experimentSkus=[];
foreach ($manifest['hypotheses']??[] as $h) {
    foreach ($h['skus']??[] as $sku) {
        if (!isset($seen[$sku]) || isset($experimentSkus[$sku])) {
            throw new RuntimeException('Invalid or repeated experiment SKU: '.$sku);
        }
        $experimentSkus[$sku]=true;
    }
}
if (count($experimentSkus)!==9) throw new RuntimeException('Hypotheses do not cover the pilot.');
echo "SERIES01_PILOT_CONTRACT_OK\n";
