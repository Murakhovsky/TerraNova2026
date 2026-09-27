<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class GrowthExperiment
{
    /**
     * @param list<GrowthExperimentVariant> $variants
     */
    public function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly string $name,
        public readonly string $hypothesis,
        public readonly GrowthExperimentDimension $dimension,
        public readonly GrowthOutcomeType $primaryOutcome,
        public readonly array $variants,
        public readonly DateTimeImmutable $createdAt,
        private GrowthExperimentStatus $status=GrowthExperimentStatus::Draft,
        private ?DateTimeImmutable $startedAt=null,
        private ?DateTimeImmutable $endedAt=null,
    ) {
        if(trim($id)===''||mb_strlen($id)>80)throw new InvalidArgumentException('Growth experiment id is invalid.');
        if(trim($name)===''||mb_strlen($name)>191)throw new InvalidArgumentException('Growth experiment name is invalid.');
        if(trim($hypothesis)===''||mb_strlen($hypothesis)>2000)throw new InvalidArgumentException('Growth experiment hypothesis is invalid.');
        if(count($variants)<2||count($variants)>12)throw new InvalidArgumentException('Growth experiment requires 2 to 12 variants.');

        $keys=[];
        $total=0;
        foreach($variants as $variant){
            if(!$variant instanceof GrowthExperimentVariant)throw new InvalidArgumentException('Growth experiment variant is invalid.');
            if(isset($keys[$variant->key]))throw new InvalidArgumentException('Growth experiment variant keys must be unique.');
            $keys[$variant->key]=true;
            $total+=$variant->allocationWeight;
        }
        if($total>100000)throw new InvalidArgumentException('Growth experiment total allocation weight is too large.');

        $this->assertTemporalState();
    }

    public function status():GrowthExperimentStatus{return $this->status;}
    public function startedAt():?DateTimeImmutable{return $this->startedAt;}
    public function endedAt():?DateTimeImmutable{return $this->endedAt;}

    public function start(DateTimeImmutable $at):void
    {
        if($this->status!==GrowthExperimentStatus::Draft)throw new DomainException('Only draft Growth experiment can start.');
        if($at<$this->createdAt)throw new InvalidArgumentException('Growth experiment cannot start before creation.');
        $this->status=GrowthExperimentStatus::Running;
        $this->startedAt=$at;
        $this->endedAt=null;
    }

    public function pause():void
    {
        if($this->status!==GrowthExperimentStatus::Running)throw new DomainException('Only running Growth experiment can pause.');
        $this->status=GrowthExperimentStatus::Paused;
    }

    public function resume():void
    {
        if($this->status!==GrowthExperimentStatus::Paused)throw new DomainException('Only paused Growth experiment can resume.');
        $this->status=GrowthExperimentStatus::Running;
    }

    public function complete(DateTimeImmutable $at):void
    {
        if(!in_array($this->status,[GrowthExperimentStatus::Running,GrowthExperimentStatus::Paused],true)){
            throw new DomainException('Only running or paused Growth experiment can complete.');
        }
        if($this->startedAt===null||$at<$this->startedAt)throw new InvalidArgumentException('Growth experiment completion time is invalid.');
        $this->status=GrowthExperimentStatus::Completed;
        $this->endedAt=$at;
    }

    public function archive():void
    {
        if(!in_array($this->status,[GrowthExperimentStatus::Draft,GrowthExperimentStatus::Completed],true)){
            throw new DomainException('Only draft or completed Growth experiment can be archived.');
        }
        $this->status=GrowthExperimentStatus::Archived;
    }

    public function variant(string $key):GrowthExperimentVariant
    {
        foreach($this->variants as $variant)if($variant->key===$key)return $variant;
        throw new InvalidArgumentException('Growth experiment variant was not found.');
    }

    public function chooseVariant(string $candidateId):GrowthExperimentVariant
    {
        $candidateId=trim($candidateId);
        if($candidateId==='')throw new InvalidArgumentException('Growth experiment candidate id is required for deterministic assignment.');
        $total=array_sum(array_map(static fn(GrowthExperimentVariant $variant):int=>$variant->allocationWeight,$this->variants));
        $bucket=hexdec(substr(hash('sha256',$this->id.':'.$candidateId),0,8))%$total;
        $cursor=0;
        foreach($this->variants as $variant){
            $cursor+=$variant->allocationWeight;
            if($bucket<$cursor)return $variant;
        }
        return $this->variants[array_key_last($this->variants)];
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'experiment_id'=>$this->id,
            'name'=>$this->name,
            'hypothesis'=>$this->hypothesis,
            'dimension'=>$this->dimension->value,
            'primary_outcome'=>$this->primaryOutcome->value,
            'variants'=>array_map(static fn(GrowthExperimentVariant $variant):array=>$variant->toArray(),$this->variants),
            'status'=>$this->status->value,
            'started_at'=>$this->startedAt?->format(DATE_ATOM),
            'ended_at'=>$this->endedAt?->format(DATE_ATOM),
            'created_at'=>$this->createdAt->format(DATE_ATOM),
        ];
    }

    private function assertTemporalState():void
    {
        match($this->status){
            GrowthExperimentStatus::Draft=>$this->assertTimes(false,false),
            GrowthExperimentStatus::Running,GrowthExperimentStatus::Paused=>$this->assertTimes(true,false),
            GrowthExperimentStatus::Completed=>$this->assertTimes(true,true),
            GrowthExperimentStatus::Archived=>$this->assertArchivedTimes(),
        };
    }

    private function assertTimes(bool $started,bool $ended):void
    {
        if(($this->startedAt!==null)!==$started||($this->endedAt!==null)!==$ended){
            throw new InvalidArgumentException('Growth experiment timestamps do not match status.');
        }
        if($this->startedAt!==null&&$this->startedAt<$this->createdAt){
            throw new InvalidArgumentException('Growth experiment started before creation.');
        }
        if($this->endedAt!==null&&($this->startedAt===null||$this->endedAt<$this->startedAt)){
            throw new InvalidArgumentException('Growth experiment ended before start.');
        }
    }

    private function assertArchivedTimes():void
    {
        if($this->startedAt===null&&$this->endedAt===null)return;
        $this->assertTimes(true,true);
    }
}
