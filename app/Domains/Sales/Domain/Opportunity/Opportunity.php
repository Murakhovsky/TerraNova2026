<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Opportunity;

use Domains\Sales\Domain\Company\CompanyId;
use Domains\Sales\Domain\Contact\ContactId;
use Domains\Sales\Domain\Pipeline\PipelineId;
use Domains\Sales\Domain\Pipeline\PipelineStageId;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Opportunity
{
    public function __construct(
        public OpportunityId $id,
        public OrganizationId $organizationId,
        public string $title,
        public OpportunityStatus $status,
        public PipelineId $pipelineId,
        public PipelineStageId $stageId,
        public ?ContactId $contactId = null,
        public ?CompanyId $companyId = null,
    ) {
        if (trim($title) === '') {
            throw new InvalidArgumentException('Opportunity title is required.');
        }
    }

    public function withStage(PipelineStageId $stageId): self
    {
        return new self($this->id, $this->organizationId, $this->title, $this->status, $this->pipelineId, $stageId, $this->contactId, $this->companyId);
    }

    public function withStatus(OpportunityStatus $status): self
    {
        return new self($this->id, $this->organizationId, $this->title, $status, $this->pipelineId, $this->stageId, $this->contactId, $this->companyId);
    }
}
