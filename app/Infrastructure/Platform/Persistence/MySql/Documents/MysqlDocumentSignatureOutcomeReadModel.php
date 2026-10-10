<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Documents;

use DateTimeImmutable;
use DomainException;
use PDO;
use Platform\Documents\Contract\DocumentSignatureOutcomeReadModelInterface;

/** Recorded signatures with signed actor and reference, not independent cryptographic verification. */
final readonly class MysqlDocumentSignatureOutcomeReadModel implements DocumentSignatureOutcomeReadModelInterface
{
    public function __construct(private PDO $connection) {}

    public function count(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($organizationId === '' || $to <= $from) {
            throw new DomainException('Invalid documents.signatures_recorded evidence scope.');
        }
        $query = $this->connection->prepare(
            "SELECT COUNT(*) FROM cos_document_signatures WHERE organization_id = :org AND status = 'signed' AND signed_at IS NOT NULL AND signature_reference IS NOT NULL AND signature_reference <> '' AND signed_by IS NOT NULL AND signed_by <> '' AND signed_at >= :from_time AND signed_at < :to_time"
        );
        $query->execute([
            'org' => $organizationId,
            'from_time' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'to_time' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
        $raw = $query->fetchColumn();
        if (!is_scalar($raw) || !preg_match('/^[0-9]+$/', (string) $raw)) {
            throw new DomainException('Invalid Domain-owned aggregate count.');
        }
        return (int) $raw;
    }
}
