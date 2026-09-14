<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql;

use Domains\Property\Application\Contract\PropertyIntelligenceRepositoryInterface;
use PDO;
use Throwable;

final readonly class MysqlPropertyIntelligenceRepository implements PropertyIntelligenceRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function save(string $organizationId, array $snapshot, array $comparables): void
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) $this->connection->beginTransaction();
        try {
            $supersede = $this->connection->prepare('UPDATE tn_property_intelligence_snapshots
                SET superseded_at=NOW()
                WHERE organization_id=:organization_id AND asset_id=:asset_id AND superseded_at IS NULL');
            $supersede->execute(['organization_id' => $organizationId, 'asset_id' => $snapshot['asset_id']]);

            $statement = $this->connection->prepare('INSERT INTO tn_property_intelligence_snapshots (
                organization_id,inference_id,asset_id,inventory_id,provider,model,method,methodology_version,evidence_hash,
                facts_json,market_signals_json,output_json,estimated_market_value_low,estimated_market_value_high,value_currency,
                liquidity_score,demand_score,market_position,price_anomaly_percent,inventory_risk,expected_dom_min,expected_dom_max,
                recommended_asking_price,confidence,explanation,correlation_id,input_tokens,output_tokens,cost_amount,cost_currency,
                generated_at,valid_until
            ) VALUES (
                :organization_id,:inference_id,:asset_id,:inventory_id,:provider,:model,:method,:methodology_version,:evidence_hash,
                :facts_json,:market_signals_json,:output_json,:estimated_market_value_low,:estimated_market_value_high,:value_currency,
                :liquidity_score,:demand_score,:market_position,:price_anomaly_percent,:inventory_risk,:expected_dom_min,:expected_dom_max,
                :recommended_asking_price,:confidence,:explanation,:correlation_id,:input_tokens,:output_tokens,:cost_amount,:cost_currency,
                :generated_at,:valid_until
            )');
            $statement->execute([
                'organization_id' => $organizationId,
                'inference_id' => $snapshot['inference_id'],
                'asset_id' => $snapshot['asset_id'],
                'inventory_id' => $snapshot['inventory_id'],
                'provider' => $snapshot['provider'],
                'model' => $snapshot['model'],
                'method' => $snapshot['method'],
                'methodology_version' => $snapshot['methodology_version'],
                'evidence_hash' => $snapshot['evidence_hash'],
                'facts_json' => $this->json($snapshot['facts']),
                'market_signals_json' => $this->json($snapshot['market_signals']),
                'output_json' => $this->json($snapshot['output']),
                'estimated_market_value_low' => $snapshot['estimated_market_value_low'],
                'estimated_market_value_high' => $snapshot['estimated_market_value_high'],
                'value_currency' => $snapshot['value_currency'],
                'liquidity_score' => $snapshot['liquidity_score'],
                'demand_score' => $snapshot['demand_score'],
                'market_position' => $snapshot['market_position'],
                'price_anomaly_percent' => $snapshot['price_anomaly_percent'],
                'inventory_risk' => $snapshot['inventory_risk'],
                'expected_dom_min' => $snapshot['expected_dom_min'],
                'expected_dom_max' => $snapshot['expected_dom_max'],
                'recommended_asking_price' => $snapshot['recommended_asking_price'],
                'confidence' => $snapshot['confidence'],
                'explanation' => $snapshot['explanation'],
                'correlation_id' => $snapshot['correlation_id'],
                'input_tokens' => $snapshot['input_tokens'],
                'output_tokens' => $snapshot['output_tokens'],
                'cost_amount' => $snapshot['cost_amount'],
                'cost_currency' => $snapshot['cost_currency'],
                'generated_at' => $snapshot['generated_at'],
                'valid_until' => $snapshot['valid_until'],
            ]);

            $comparable = $this->connection->prepare('INSERT INTO tn_property_intelligence_comparables (
                organization_id,inference_id,comparable_asset_id,comparable_inventory_id,similarity_score,
                selected_price,selected_currency,selected_area,selected_price_per_sqm,reason_json
            ) VALUES (
                :organization_id,:inference_id,:comparable_asset_id,:comparable_inventory_id,:similarity_score,
                :selected_price,:selected_currency,:selected_area,:selected_price_per_sqm,:reason_json
            )');
            foreach ($comparables as $row) {
                $assetId = trim((string) ($row['asset_id'] ?? ''));
                if ($assetId === '') continue;
                $comparable->execute([
                    'organization_id' => $organizationId,
                    'inference_id' => $snapshot['inference_id'],
                    'comparable_asset_id' => $assetId,
                    'comparable_inventory_id' => $row['inventory_id'] ?? null,
                    'similarity_score' => (float) ($row['similarity_score'] ?? 0),
                    'selected_price' => $row['price_amount'] ?? null,
                    'selected_currency' => $row['price_currency'] ?? null,
                    'selected_area' => $row['area_total'] ?? null,
                    'selected_price_per_sqm' => $row['price_per_sqm'] ?? null,
                    'reason_json' => $this->json($row['reasons'] ?? []),
                ]);
            }
            if ($ownsTransaction) $this->connection->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            throw $e;
        }
    }

    public function latest(string $organizationId, string $assetId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM tn_property_intelligence_snapshots
            WHERE organization_id=:organization_id AND asset_id=:asset_id AND superseded_at IS NULL
            ORDER BY generated_at DESC,id DESC LIMIT 1');
        $statement->execute(['organization_id' => $organizationId, 'asset_id' => $assetId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->decode($row);
    }

    public function history(string $organizationId, string $assetId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->connection->prepare('SELECT * FROM tn_property_intelligence_snapshots
            WHERE organization_id=:organization_id AND asset_id=:asset_id
            ORDER BY generated_at DESC,id DESC LIMIT ' . $limit);
        $statement->execute(['organization_id' => $organizationId, 'asset_id' => $assetId]);
        return array_map(fn (array $row): array => $this->decode($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decode(array $row): array
    {
        foreach (['facts_json','market_signals_json','output_json'] as $field) {
            $row[$field] = isset($row[$field]) ? json_decode((string) $row[$field], true, flags: JSON_THROW_ON_ERROR) : null;
        }
        return $row;
    }
}
