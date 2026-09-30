<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Domain\EngagementExecutionLimitPolicy;

function expectGrowthV0370(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$policy=new EngagementExecutionLimitPolicy(10,24);
$now=new DateTimeImmutable('2026-09-24T12:00:00+00:00');

$allowed=$policy->evaluate(3,null,$now);
expectGrowthV0370($allowed['allowed']===true,'Growth execution limits must allow volume below the cap.');

$daily=$policy->evaluate(10,null,$now);
expectGrowthV0370($daily['allowed']===false&&$daily['code']==='pre_handoff_daily_limit_reached','Growth daily outreach limit was not enforced.');
expectGrowthV0370($daily['next_allowed_at']==='2026-09-25T00:00:00+00:00','Growth daily limit reset boundary is incorrect.');

$cooldown=$policy->evaluate(1,new DateTimeImmutable('2026-09-24T06:00:00+00:00'),$now);
expectGrowthV0370($cooldown['allowed']===false&&$cooldown['code']==='pre_handoff_contact_cooldown','Growth contact cooldown was not enforced.');
expectGrowthV0370($cooldown['next_allowed_at']==='2026-09-25T06:00:00+00:00','Growth contact cooldown expiry is incorrect.');

$expired=$policy->evaluate(1,new DateTimeImmutable('2026-09-23T11:59:59+00:00'),$now);
expectGrowthV0370($expired['allowed']===true,'Expired Growth contact cooldown must allow outreach.');

echo "Growth V0.37 Pre-Handoff Outreach Guardrails contracts passed.\n";
