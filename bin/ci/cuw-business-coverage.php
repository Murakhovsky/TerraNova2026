<?php
declare(strict_types=1);

/** Regenerate the static CUW-E census from the one canonical Capability Catalog.
 * Runtime facts are deliberately UNVERIFIED; no actor/tenant assumptions.
 * Usage: php bin/ci/cuw-business-coverage.php > docs/04-implementation/cos-cuw-e-experience-coverage.csv
 */
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$definitions=[];
foreach(glob($root.'/app/Domains/*/module.php') as $path)$definitions[]=\Kernel\Module\ModuleDefinition::fromArray(require $path);
$catalog=new \Kernel\Module\CanonicalCapabilityCatalog(new \Kernel\Module\ModuleCatalog($definitions));
$presentation=[
    'property.asset.search'=>'/workspace/unified/business/property.asset',
    'property.asset.get'=>'/workspace/unified/business/property.asset',
    'property.asset.create'=>'/workspace/unified/actions',
    'property.bulk.create'=>'/workspace/property/bulk',
    'sales.workspace.leads.list'=>'/sales/leads',
    'sales.workspace.deals.list'=>'/sales/deals',
    'sales.order.list'=>'/workspace/unified/business/sales.order',
    'sales.order.read'=>'/workspace/unified/business/sales.order',
    'sales.order.lifecycle.read'=>'/workspace/unified/business/sales.order',
    'sales.order.lifecycle.confirm'=>'/workspace/unified/business/sales.order/confirm',
    'product_management.product.search'=>'/workspace/unified/business/pm.catalog',
    'product_management.product.get'=>'/workspace/unified/business/pm.catalog',
    'product_management.product.create'=>'/product-management',
    'marketing.campaign.search'=>'/workspace/unified/business/marketing.campaigns',
    'marketing.campaign.get'=>'/workspace/unified/business/marketing.campaigns',
    'marketing.campaign.create'=>'/marketing/campaigns',
    'finance.budget.envelope.draft.create'=>'/workspace/unified/business/finance.budget/new',
    'finance.budget.envelope.list'=>'/workspace/unified/business/finance.budget',
    'finance.budget.envelope.get'=>'/workspace/unified/business/finance.budget',
    'finance.budget.reservation.list'=>'/workspace/unified/business/finance.budget',
    'finance.order.financial_evidence.read'=>'/workspace/unified/business/finance.payments',
    'finance.order.payment.operations.read'=>'/workspace/unified/business/finance.payments',
];
$out=fopen('php://stdout','w');
fputcsv($out,['owner','capability','defined','registered','tenant_available','authorized','ui_ready','execution_verified','presentation_source','binding_source'],',','"','');
$declared=$catalog->declared();
ksort($declared);
foreach($declared as $id=>$owner) {
    $contract=$catalog->describe($id);
    fputcsv($out,[$owner,$id,$contract===null?'DECLARATION_ONLY':'TYPED','UNVERIFIED','UNVERIFIED','UNVERIFIED','UNVERIFIED','UNVERIFIED',$presentation[$id]??'',$contract?->executionBinding??''],',','"','');
}
