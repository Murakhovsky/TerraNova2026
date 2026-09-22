<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\BuyingCommitteeAssessment;
use Domains\Growth\Domain\ContactSnapshot;
use Domains\Growth\Domain\GrowthContact;

interface GrowthBuyingCommitteeRepositoryInterface
{
    public function createContact(GrowthContact $contact,int $actorId): void;
    /** @return array<string,mixed>|null */
    public function findContactByIdentity(string $organizationId,string $identityType,string $identityValue): ?array;
    /** @return array<string,mixed>|null */
    public function viewContact(string $organizationId,string $contactId): ?array;

    public function linkContactToAccount(string $organizationId,string $accountId,string $contactId,int $actorId): void;
    public function isContactLinked(string $organizationId,string $accountId,string $contactId): bool;
    public function createContactSnapshot(ContactSnapshot $snapshot,int $actorId): void;
    public function viewContactSnapshot(string $organizationId,string $snapshotId): ?ContactSnapshot;
    /** @return list<ContactSnapshot> */
    public function latestContactSnapshotsForAccount(string $organizationId,string $accountId): array;
    /** @return list<array<string,mixed>> */
    public function accountContacts(string $organizationId,string $accountId): array;

    public function createAssessment(string $assessmentId,string $organizationId,BuyingCommitteeAssessment $assessment,int $actorId): void;
    /** @return array<string,mixed>|null */
    public function viewAssessment(string $organizationId,string $assessmentId): ?array;
    /** @return array<string,mixed>|null */
    public function latestAssessment(string $organizationId,string $accountId): ?array;
}
