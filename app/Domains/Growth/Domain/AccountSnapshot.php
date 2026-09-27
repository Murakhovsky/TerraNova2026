<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class AccountSnapshot
{
    /**
     * @param array<string,scalar|null> $firmographics
     * @param list<string> $technologies
     * @param list<string> $hiring
     * @param list<string> $recentChanges
     * @param list<string> $signalTypes
     * @param list<string> $sourceReferences
     */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $accountId,
        public array $firmographics,
        public array $technologies,
        public array $hiring,
        public array $recentChanges,
        public array $signalTypes,
        public array $sourceReferences,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $capturedAt,
    ) {
        if(trim($id)===''||trim($accountId)==='')throw new InvalidArgumentException('Growth AccountSnapshot id and accountId are required.');
        if($firmographics===[]&&$technologies===[]&&$hiring===[]&&$recentChanges===[]&&$signalTypes===[]) {
            throw new InvalidArgumentException('Growth AccountSnapshot must contain observable facts.');
        }
        if($sourceReferences===[])throw new InvalidArgumentException('Growth AccountSnapshot requires source references.');
        foreach($firmographics as $key=>$value){
            if(!is_string($key)||$key===''||(!is_scalar($value)&&$value!==null))throw new InvalidArgumentException('Growth AccountSnapshot firmographics are invalid.');
        }
        foreach([$technologies,$hiring,$recentChanges,$signalTypes,$sourceReferences] as $values){
            foreach($values as $value)if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth AccountSnapshot list value is invalid.');
        }
        if($capturedAt<$observedAt)throw new InvalidArgumentException('Growth AccountSnapshot cannot be captured before observed time.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'snapshot_id'=>$this->id,
            'account_id'=>$this->accountId,
            'firmographics'=>$this->firmographics,
            'technologies'=>$this->technologies,
            'hiring'=>$this->hiring,
            'recent_changes'=>$this->recentChanges,
            'signal_types'=>$this->signalTypes,
            'source_references'=>$this->sourceReferences,
            'observed_at'=>$this->observedAt->format(DATE_ATOM),
            'captured_at'=>$this->capturedAt->format(DATE_ATOM),
        ];
    }
}
