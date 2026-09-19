<?php
declare(strict_types=1);

namespace App\Application\Documents\Query;

use Kernel\Application\Query\QueryHandlerInterface;
use Platform\Documents\Contract\DocumentsRepositoryInterface;

final readonly class GetDocumentQueryHandler implements QueryHandlerInterface
{
    public function __construct(private DocumentsRepositoryInterface $documents) {}
    public function __invoke(GetDocumentQuery $query):?array
    {
        return $this->documents->view($query->organizationId->value(),$query->documentId);
    }
}
