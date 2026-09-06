<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
interface DealStageRepositoryInterface
{
    public function getForStageChange(string $organizationId,string $dealId):?array;
    public function changeStage(string $organizationId,string $dealId,string $pipelineId,string $stageId,string $legacyStage,float $probability,bool $terminal,bool $won,bool $lost):bool;
}
