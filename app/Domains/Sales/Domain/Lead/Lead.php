<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Lead;

use Domains\Sales\Domain\Company\CompanyId;
use Domains\Sales\Domain\Contact\ContactId;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Lead
{
    public function __construct(
        public LeadId $id,
        public OrganizationId $organizationId,
        public LeadStatus $status,
        public ?ContactId $contactId = null,
        public ?CompanyId $companyId = null,
        public ?string $source = null,
    ) {
        if ($source !== null && strlen(trim($source)) > 120) {
            throw new InvalidArgumentException('Lead source must not exceed 120 characters.');
        }
    }

    public function withStatus(LeadStatus $status): self
    {
        return new self($this->id, $this->organizationId, $status, $this->contactId, $this->companyId, $this->source);
    }
}
