<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;
use Domains\Diagnostic\Model\DiagnosticConcurrencyException;
use Domains\Diagnostic\Model\DiagnosticPack;
use Domains\Diagnostic\Model\DiagnosticPackStatus;
use PDO;

final readonly class MysqlDiagnosticPackRepository implements DiagnosticPackRepositoryInterface
{
    public function __construct(
        private PDO $connection,
        private MethodologyPackSerializer $serializer = new MethodologyPackSerializer(),
    ) {
    }

    public function save(string $organizationId, DiagnosticPack $pack, ?int $expectedLockVersion = null): void
    {
        $values = [
            'organization_id' => $organizationId,
            'pack_id' => $pack->id(),
            'version' => $pack->version(),
            'name' => $pack->name(),
            'target_domain' => $pack->targetDomain(),
            'status' => $pack->status()->value,
            'methodology_json' => $this->serializer->encode($pack->methodology()),
            'content_hash' => $pack->contentHash(),
            'lock_version' => $pack->lockVersion(),
            'published_at' => $pack->publishedAt()?->format('Y-m-d H:i:s.u'),
        ];
        if ($expectedLockVersion === null) {
            $statement = $this->connection->prepare(
                'INSERT INTO diagnostic_packs '
                . '(organization_id, pack_id, version, name, target_domain, status, methodology_json, content_hash, lock_version, published_at) '
                . 'VALUES (:organization_id, :pack_id, :version, :name, :target_domain, :status, CAST(:methodology_json AS JSON), :content_hash, :lock_version, :published_at)'
            );
            $statement->execute($values);
            return;
        }

        $values['expected_lock_version'] = $expectedLockVersion;
        $statement = $this->connection->prepare(
            'UPDATE diagnostic_packs SET name = :name, target_domain = :target_domain, status = :status, '
            . 'methodology_json = CAST(:methodology_json AS JSON), content_hash = :content_hash, '
            . 'lock_version = :lock_version, published_at = :published_at '
            . 'WHERE organization_id = :organization_id AND pack_id = :pack_id AND version = :version '
            . 'AND lock_version = :expected_lock_version'
        );
        $statement->execute($values);
        if ($statement->rowCount() !== 1) {
            throw new DiagnosticConcurrencyException('Diagnostic pack changed concurrently.');
        }
    }

    public function get(string $organizationId, string $packId, int $version): ?DiagnosticPack
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM diagnostic_packs WHERE organization_id = :organization_id '
            . 'AND pack_id = :pack_id AND version = :version LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'pack_id' => $packId, 'version' => $version]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        return DiagnosticPack::rehydrate(
            (string) $row['target_domain'],
            $this->serializer->decode((string) $row['methodology_json']),
            DiagnosticPackStatus::from((string) $row['status']),
            $this->date($row['published_at'] ?? null),
            (int) $row['lock_version'],
            (string) $row['content_hash'],
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable((string) $value);
    }
}
