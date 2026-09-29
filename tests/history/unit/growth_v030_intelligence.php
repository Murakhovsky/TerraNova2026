<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Domain\AccountSnapshot;
use Domains\Growth\Domain\IcpCriteria;
use Domains\Growth\Domain\IcpMatcher;
use Domains\Growth\Domain\IcpProfile;
use Domains\Growth\Domain\IcpProfileStatus;
use Domains\Growth\Automation\Event\GrowthEventType;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV030(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$org=OrganizationId::fromString('org-1');
$criteria=new IcpCriteria(
    industries:['saas'],
    regions:['ukraine'],
    minEmployees:20,
    maxEmployees:300,
    technologies:['hubspot','slack'],
    requiredSignalTypes:['executive_change'],
);
$profile=IcpProfile::draft('icp-1',$org,'Scaling SaaS',$criteria);
expectGrowthV030($profile->status()===IcpProfileStatus::Draft,'ICP must start as draft.');
$profile->activate();
expectGrowthV030($profile->status()===IcpProfileStatus::Active,'ICP activation failed.');
$revision=$profile->revise('Scaling SaaS V2',new IcpCriteria(industries:['saas'],regions:['ukraine'],minEmployees:30,maxEmployees:500));
expectGrowthV030($revision->revision===2&&$revision->status()===IcpProfileStatus::Draft,'ICP revision must fork active profile into a new draft revision.');

$captured=new DateTimeImmutable('2026-09-21T12:05:00+00:00');
$snapshot=new AccountSnapshot(
    'snap-1',$org,'account-1',
    ['industry'=>'saas','country'=>'ukraine','employee_count'=>120],
    ['hubspot','slack'],['revops manager'],['new coo'],['executive_change'],['source-1'],
    new DateTimeImmutable('2026-09-21T12:00:00+00:00'),$captured,
);
$match=(new IcpMatcher())->match($profile,$snapshot,$captured);
expectGrowthV030($match->fit->score===100,'Fully matching AccountSnapshot must score 100 ICP fit.');
expectGrowthV030($match->gaps===[],'Fully matching AccountSnapshot must not expose ICP gaps.');
expectGrowthV030($match->fit->modelVersion===IcpMatcher::MODEL_VERSION,'ICP score must preserve model version.');
expectGrowthV030(count(array_unique(GrowthEventType::values()))===count(GrowthEventType::values()),'Growth event types must remain unique.');
expectGrowthV030(in_array(GrowthEventType::ICP_REVISED,GrowthEventType::values(),true),'Growth ICP revised event is missing.');

echo "Growth V0.3 ICP and Account Intelligence contracts passed.\n";
