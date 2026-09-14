<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
$pack=(new Domains\Diagnostic\Methodology\Loader\PackLoader())->load(['id'=>'regression','version'=>1,'name'=>'Regression','sections'=>[['id'=>'sales','name'=>'Sales']],'metrics'=>[],'facts'=>[['id'=>'crm','name'=>'CRM','type'=>'boolean']],'criteria'=>[['id'=>'lead-processing','name'=>'Lead processing','section'=>'sales','required'=>['fact.crm']]]]);
if(($pack->criteria[0]->id??null)!=='lead-processing')throw new RuntimeException('PackLoader corrupted criterion id.');
echo "Diagnostic PackLoader criterion regression passed.\n";
