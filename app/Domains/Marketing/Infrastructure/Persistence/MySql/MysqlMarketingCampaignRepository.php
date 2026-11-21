<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingCampaignRepository;
use PDO;
use PDOException;
use Throwable;

final readonly class MysqlMarketingCampaignRepository implements MarketingCampaignRepository
{
    public function __construct(private PDO $connection) {}

    public function createDraft(string $organizationId, string $ownerId, array $draft, string $idempotencyKey): array
    {
        $fingerprint = hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $pdo = $this->connection;
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT campaign_id, fingerprint FROM marketing_campaign_intents WHERE organization_id=? AND idempotency_key=? FOR UPDATE');
            $statement->execute([$organizationId, $idempotencyKey]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['fingerprint'], $fingerprint)) throw new DomainException('Idempotency conflict.');
                $pdo->commit();
                return $this->get($organizationId, (string)$existing['campaign_id']) ?? throw new DomainException('Missing committed draft.');
            }
            $id = 'mkt-'.bin2hex(random_bytes(12));
            $pdo->prepare('INSERT INTO marketing_campaigns (organization_id,id,owner_id,name,business_objective,brief,product_refs_json,status,revision) VALUES (?,?,?,?,?,?,?, ?,1)')
                ->execute([$organizationId,$id,$ownerId,$draft['name'],$draft['business_objective'],$draft['brief'],json_encode($draft['product_refs'], JSON_THROW_ON_ERROR),'DRAFT']);
            $pdo->prepare('INSERT INTO marketing_campaign_revisions (organization_id,campaign_id,revision,actor_id,snapshot_json,reason) VALUES (?,?,1,?,?,?)')
                ->execute([$organizationId,$id,$ownerId,json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),'initial_draft']);
            $pdo->prepare('INSERT INTO marketing_campaign_intents (organization_id,idempotency_key,fingerprint,campaign_id) VALUES (?,?,?,?)')
                ->execute([$organizationId,$idempotencyKey,$fingerprint,$id]);
            $pdo->commit();
            return $this->get($organizationId,$id) ?? throw new DomainException('Committed draft missing.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                $stmt=$pdo->prepare('SELECT campaign_id,fingerprint FROM marketing_campaign_intents WHERE organization_id=? AND idempotency_key=?');
                $stmt->execute([$organizationId,$idempotencyKey]);
                $entry=$stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($entry)) {
                    if (!hash_equals((string)$entry['fingerprint'],$fingerprint)) throw new DomainException('Idempotency conflict.');
                    return $this->get($organizationId,(string)$entry['campaign_id']) ?? throw new DomainException('Draft receipt missing.');
                }
            }
            throw $e;
        }
    }

    public function get(string $organizationId, string $campaignId): ?array
    {
        $stmt=$this->connection->prepare('SELECT id,owner_id,name,business_objective,brief,product_refs_json,status,revision,created_at,updated_at FROM marketing_campaigns WHERE organization_id=? AND id=?');
        $stmt->execute([$organizationId,$campaignId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::normalize($row) : null;
    }

    public function search(string $organizationId, string $query = '', int $limit = 50): array
    {
        $stmt=$this->connection->prepare('SELECT id,owner_id,name,business_objective,brief,product_refs_json,status,revision,created_at,updated_at FROM marketing_campaigns WHERE organization_id=? AND name LIKE ? ORDER BY updated_at DESC,id DESC LIMIT '.(int)$limit);
        $stmt->execute([$organizationId,'%'.str_replace(['%','_'],['\%','\_'],$query).'%']);
        return array_map(self::normalize(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function normalize(array $row): array
    {
        $row['product_refs'] = json_decode((string)($row['product_refs_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
        unset($row['product_refs_json']);
        $row['revision'] = (int)$row['revision'];
        return $row;
    }
}
