<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Company;

use Kernel\Shared\Domain\OrganizationId;

interface CompanyRepositoryInterface
{
    public function find(OrganizationId $organizationId, CompanyId $companyId): ?Company;
    public function save(Company $company): void;
}
