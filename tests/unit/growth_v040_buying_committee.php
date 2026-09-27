<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Domain\BuyingCommitteeAnalyzer;
use Domains\Growth\Domain\BuyingCommitteeAssessment;
use Domains\Growth\Domain\BuyingRole;
use Domains\Growth\Domain\ContactSnapshot;
use Domains\Growth\Domain\RelationshipStrength;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV040(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$org=OrganizationId::fromString('org-1');
$at=new DateTimeImmutable('2026-09-22T08:00:00+00:00');

$champion=new ContactSnapshot(
    'snap-champion',$org,'account-1','contact-1','Head of RevOps','Revenue','head',
    [BuyingRole::Champion,BuyingRole::TechnicalBuyer],RelationshipStrength::Strong,
    'Three completed workshops',['source-1'],$at,$at,
);
$buyer=new ContactSnapshot(
    'snap-buyer',$org,'account-1','contact-2','COO','Operations','c-level',
    [BuyingRole::EconomicBuyer],RelationshipStrength::Medium,
    'Direct discovery conversation',['source-2'],$at,$at,
);
$blocker=new ContactSnapshot(
    'snap-blocker',$org,'account-1','contact-3','Procurement Lead','Procurement','lead',
    [BuyingRole::Procurement,BuyingRole::Blocker],RelationshipStrength::Weak,
    'Only formal procurement contact',['source-3'],$at,$at,
);

$assessment=(new BuyingCommitteeAnalyzer())->assess(
    'account-1',
    [BuyingRole::Champion,BuyingRole::EconomicBuyer,BuyingRole::TechnicalBuyer,BuyingRole::Legal],
    [$champion,$buyer,$blocker],
    $at,
);

expectGrowthV040($assessment->coverageScore===75,'Buying committee coverage score must expose one missing role.');
expectGrowthV040(count($assessment->gaps)===1&&$assessment->gaps[0]===BuyingRole::Legal,'Buying committee Legal gap was not detected.');
expectGrowthV040($assessment->championContactIds===['contact-1'],'Champion must be explicit.');
expectGrowthV040($assessment->blockerContactIds===['contact-3'],'Blocker must be explicit.');
expectGrowthV040(in_array('contact-3',$assessment->weakRelationshipContactIds,true),'Weak relationship risk must be explicit.');
expectGrowthV040($assessment->relationshipScore===62,'Relationship score must average latest evidence.');
expectGrowthV040(($assessment->toArray()['model_version']??null)===BuyingCommitteeAssessment::MODEL_VERSION,'Committee assessment model version must be persisted.');

echo "Growth V0.4 Buying Committee Intelligence contracts passed.\n";
