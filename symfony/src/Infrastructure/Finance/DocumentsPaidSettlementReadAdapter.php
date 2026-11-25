<?php
declare(strict_types=1);
namespace App\Infrastructure\Finance;

use Domains\Finance\Application\Contract\DocumentsPaidSettlementReadPort;
use Domains\Documents\Application\Service\VerifiedUploadedPdfReadService;
use Kernel\Tenant\Model\TenantContext;

final readonly class DocumentsPaidSettlementReadAdapter implements DocumentsPaidSettlementReadPort
{
    public function __construct(private VerifiedUploadedPdfReadService $documents){}
    public function document(TenantContext $actor,string $documentRef):array
    {return $this->documents->document($actor,$documentRef);}
}
