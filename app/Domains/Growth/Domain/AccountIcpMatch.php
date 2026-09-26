<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AccountIcpMatch
{
    /** @param list<string> $matchedGroups @param list<string> $gaps */
    public function __construct(
        public string $accountId,
        public string $profileId,
        public int $profileRevision,
        public ScoreDimension $fit,
        public array $matchedGroups,
        public array $gaps,
        public DateTimeImmutable $scoredAt,
    ) {
        if(trim($accountId)===''||trim($profileId)===''||$profileRevision<1)throw new InvalidArgumentException('Invalid Growth Account ICP match.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'account_id'=>$this->accountId,
            'profile_id'=>$this->profileId,
            'profile_revision'=>$this->profileRevision,
            'fit'=>$this->fit->toArray(),
            'matched_groups'=>$this->matchedGroups,
            'gaps'=>$this->gaps,
            'scored_at'=>$this->scoredAt->format(DATE_ATOM),
        ];
    }
}
