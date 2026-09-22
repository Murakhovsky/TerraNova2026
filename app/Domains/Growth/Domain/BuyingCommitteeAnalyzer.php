<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BuyingCommitteeAnalyzer
{
    /**
     * @param list<BuyingRole> $requiredRoles
     * @param list<ContactSnapshot> $snapshots
     */
    public function assess(string $accountId,array $requiredRoles,array $snapshots,DateTimeImmutable $assessedAt): BuyingCommitteeAssessment
    {
        if($requiredRoles===[])throw new InvalidArgumentException('Buying committee requires at least one required role.');
        if($snapshots===[])throw new InvalidArgumentException('Buying committee requires contact evidence.');

        $coverage=[];
        $champions=[];
        $blockers=[];
        $weak=[];
        $snapshotIds=[];
        $relationshipWeights=[];

        foreach($snapshots as $snapshot){
            if(!$snapshot instanceof ContactSnapshot)throw new InvalidArgumentException('Buying committee snapshot is invalid.');
            if($snapshot->accountId!==$accountId)throw new InvalidArgumentException('Buying committee snapshots must belong to one account.');
            $snapshotIds[]=$snapshot->id;
            $relationshipWeights[]=$snapshot->relationshipStrength->weight();
            if($snapshot->relationshipStrength->weight()<60)$weak[]=$snapshot->contactId;
            foreach($snapshot->buyingRoles as $role){
                $coverage[$role->value]??=[];
                $coverage[$role->value][]=$snapshot->contactId;
                if($role===BuyingRole::Champion)$champions[]=$snapshot->contactId;
                if($role===BuyingRole::Blocker)$blockers[]=$snapshot->contactId;
            }
        }

        foreach($coverage as &$contactIds)$contactIds=array_values(array_unique($contactIds));
        unset($contactIds);

        $gaps=[];
        foreach($requiredRoles as $role){
            if(($coverage[$role->value]??[])===[])$gaps[]=$role;
        }

        $coverageScore=(int)round(100*(count($requiredRoles)-count($gaps))/count($requiredRoles));
        $relationshipScore=(int)round(array_sum($relationshipWeights)/count($relationshipWeights));

        return new BuyingCommitteeAssessment(
            $accountId,$requiredRoles,$coverage,$gaps,
            array_values(array_unique($champions)),
            array_values(array_unique($blockers)),
            array_values(array_unique($weak)),
            $coverageScore,$relationshipScore,array_values(array_unique($snapshotIds)),$assessedAt,
        );
    }
}
