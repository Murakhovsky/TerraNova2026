<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Contact;

use Kernel\Shared\Domain\OrganizationId;

interface ContactRepositoryInterface
{
    public function find(OrganizationId $organizationId, ContactId $contactId): ?Contact;
    public function save(Contact $contact): void;
}
