<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Platform\Orchestration\Goal\FederationGolden50GoalProfile;

$enabled = FederationGolden50GoalProfile::capabilities('federation.verified_proposals',50);
if (count($enabled)!==5
    || count(array_unique($enabled))!==5
    || $enabled[0]!=='growth.market.discovery'
    || $enabled[4]!=='documents.proposal.prepare') {
    throw new RuntimeException('Golden fifty Goal capability profile has drifted.');
}
foreach ([0,1,49,51,1000000] as $target) {
    try {
        FederationGolden50GoalProfile::capabilities('federation.verified_proposals',$target);
        throw new RuntimeException('A non-fifty Golden Goal was allowed.');
    } catch (DomainException) {}
}
foreach (['sales.won_deals','documents.signatures_recorded','unknown.metric'] as $criterion) {
    if (FederationGolden50GoalProfile::capabilities($criterion,50)!==[]) {
        throw new RuntimeException('Golden reference capabilities were granted to an unrelated Goal.');
    }
}
echo "Federation Golden 50 profile: explicit five-capability grant and target isolation PASS.\n";
