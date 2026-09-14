<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Application\Contract\PropertyIdentityWorkflowRepositoryInterface;
use Domains\Property\Application\Service\PropertyIdentityWorkflowService;
use Domains\Property\Infrastructure\Network\Reso\ResoPropertyNetworkConnector;
use Domains\Property\Infrastructure\Network\Reso\ResoWebApiTransportInterface;
use Domains\Property\Network\PropertyNetworkRecord;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$repo = new class implements PropertyIdentityWorkflowRepositoryInterface {
    public array $materialized = [];
    public array $recorded = [];
    public array $completed = [];

    public function context(string $organizationId, int $submissionId, int $legacyPropertyId): ?array
    {
        return [
            'type_code' => 'apartment',
            'external_reference_keys' => ['reso:listing:L-52'],
            'address_canonical_key' => 'UA|Lviv|Forest 1|12',
            'total_area' => 64.0,
        ];
    }
    public function candidates(string $organizationId, string $typeCode, int $limit = 200): array
    {
        return [[
            'asset_id' => 'ASSET-52',
            'external_reference_keys' => ['reso:listing:L-52'],
            'address_canonical_key' => 'UA|Lviv|Forest 1|12',
            'total_area' => 64.0,
        ]];
    }
    public function canonicalForLegacy(string $organizationId, int $legacyPropertyId): ?string { return null; }
    public function recordResolution(string $organizationId, int $submissionId, ?string $candidateAssetId, ?string $resolvedAssetId, string $decision, float $score, array $signals, array $reasons, string $reviewStatus): int
    {
        $this->recorded[] = compact('candidateAssetId', 'resolvedAssetId', 'decision', 'score', 'reviewStatus');
        return 77;
    }
    public function materialize(string $organizationId, int $submissionId, int $legacyPropertyId, array $context, ?string $targetAssetId = null): string
    {
        $assetId = $targetAssetId ?? 'ASSET-CREATED';
        $this->materialized[] = [$legacyPropertyId, $assetId];
        return $assetId;
    }
    public function pendingReviews(string $organizationId, int $limit = 100): array { return []; }
    public function resolution(string $organizationId, int $resolutionId): ?array
    {
        return ['review_status' => 'pending', 'submission_id' => 9, 'legacy_property_id' => 52, 'candidate_asset_id' => 'ASSET-52'];
    }
    public function completeReview(string $organizationId, int $resolutionId, string $decision, string $assetId, ?string $reviewerReference, ?string $note): void
    {
        $this->completed[] = compact('resolutionId', 'decision', 'assetId', 'reviewerReference', 'note');
    }
};

$identity = new PropertyIdentityWorkflowService($repo);
$resolved = $identity->resolvePublished('org-a', 9, 52);
$assert($resolved['decision'] === 'merge' && $resolved['asset_id'] === 'ASSET-52', 'Exact external reference must merge into the canonical asset.');
$assert($repo->materialized[0][1] === 'ASSET-52', 'Identity merge must materialize against the chosen canonical asset.');
$reviewed = $identity->review('org-a', 77, 'create', reviewerReference: 'USER:7', note: 'Distinct unit confirmed.');
$assert($reviewed['asset_id'] === 'ASSET-CREATED' && $repo->completed[0]['decision'] === 'create', 'Human review must support explicit canonical CREATE.');

$transport = new class implements ResoWebApiTransportInterface {
    public array $pushed = [];
    public function pull(string $configurationReference, ?string $cursor, int $limit): array
    {
        if ($configurationReference !== 'secret-ref://reso-partner') throw new RuntimeException('Configuration reference drifted.');
        return [
            'records' => [
                ['ResourceName' => 'Property', 'PropertyKey' => 'P-52', 'ModificationTimestamp' => '2026-09-14T18:00:00Z', 'payload' => ['City' => 'Lviv', 'LivingArea' => 64]],
                ['ResourceName' => 'Property', 'PropertyKey' => 'P-OLD', 'operation' => 'DELETE', 'payload' => []],
            ],
            'next_cursor' => 'cursor-2',
            'has_more' => true,
            'metadata' => ['standard' => 'RESO Web API'],
        ];
    }
    public function push(string $configurationReference, array $records, ?string $cursor): array
    {
        $this->pushed = $records;
        return ['succeeded' => array_column($records, 'key'), 'failed' => [], 'next_cursor' => 'export-2'];
    }
};

$connector = new ResoPropertyNetworkConnector($transport);
$descriptor = ['configuration_reference' => 'secret-ref://reso-partner'];
$batch = $connector->pull($descriptor, 'cursor-1', 50);
$assert($connector->code() === 'reso_web_api', 'RESO adapter code drifted.');
$assert(count($batch->records) === 2 && $batch->records[0]->externalId === 'P-52', 'RESO pull mapping failed.');
$assert($batch->records[1]->operation === PropertyNetworkRecord::DELETE, 'RESO DELETE must remain a tombstone signal.');
$delivery = $connector->push($descriptor, [$batch->records[0]], 'export-1');
$assert($delivery->succeeded('property:P-52') && $transport->pushed[0]['external_id'] === 'P-52', 'RESO outbound mapping/ack failed.');

echo "Property V0.11 identity + RESO connector: OK\n";
