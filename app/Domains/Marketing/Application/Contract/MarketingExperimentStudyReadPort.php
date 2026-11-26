<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

interface MarketingExperimentStudyReadPort
{
    /** Read back exact provider split configuration; never infer statistical results or execute writes. */
    public function inspectSplitStudy(string $org,string $providerAccountId,string $ref,string $studyId,string $controlCampaign,string $candidateCampaign,string $from,string $to):array;
}
