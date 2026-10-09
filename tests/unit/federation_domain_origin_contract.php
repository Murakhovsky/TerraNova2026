<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/symfony/src/Persistence/Federation/FederationOutcomeOriginContract.php';
require dirname(__DIR__, 2) . '/symfony/src/Persistence/Federation/FederationOutcomeOriginRecorder.php';

use App\Persistence\Federation\FederationOutcomeOriginContract;
use App\Persistence\Federation\FederationOutcomeOriginRecorder;

foreach ([
    ['capital_markets', 'capital_markets.research.result.record', 'research_result', 'result-a'],
    ['platform.documents', 'documents.signature.sign', 'document_signature', 'signature-a'],
] as [$domain, $type, $target, $id]) {
    FederationOutcomeOriginContract::assertTarget($domain, $type, $target, $id, $id);
    if (!FederationOutcomeOriginContract::canLink($domain)
        || FederationOutcomeOriginContract::actionType($domain) !== $type) {
        throw new RuntimeException('Domain outcome Action contract changed.');
    }
    foreach ([
        ['sales.create_task', $target, $id, $id],
        [$type, 'deal', $id, $id],
        [$type, $target, $id, 'foreign-result'],
        [$type, $target, null, $id],
    ] as [$otherType, $otherTarget, $otherId, $outcome]) {
        try {
            FederationOutcomeOriginContract::assertTarget(
                $domain, $otherType, $otherTarget, $otherId, $outcome,
            );
            throw new RuntimeException('Forged/mismatched Action was accepted as origin.');
        } catch (DomainException) {}
    }
}
foreach (['growth', '', 'sales'] as $invalid) {
    if (FederationOutcomeOriginContract::canLink($invalid)) {
        throw new RuntimeException('Unowned Domain can create privileged origin.');
    }
}
$payload = ['status' => 'VALIDATED', 'created_at' => '2026-10-09 11:22:33.000000'];
$one = FederationOutcomeOriginRecorder::fingerprint('tenant-one', 'capital_markets', 'result-1', $payload);
$two = FederationOutcomeOriginRecorder::fingerprint('tenant-one', 'capital_markets', 'result-1', array_reverse($payload, true));
if ($one !== $two || strlen($one) !== 64
    || $one === FederationOutcomeOriginRecorder::fingerprint('tenant-two', 'capital_markets', 'result-1', $payload)
    || $one === FederationOutcomeOriginRecorder::fingerprint('tenant-one', 'capital_markets', 'result-2', $payload)
    || $one === FederationOutcomeOriginRecorder::fingerprint('tenant-one', 'capital_markets', 'result-1',
        ['status' => 'REJECTED', 'created_at' => '2026-10-09 11:22:33.000000'])) {
    throw new RuntimeException('Native source fingerprint is not tenant-bound and change-detecting.');
}
echo "Federation Research/Documents origin contracts: strict Action targeting and fingerprints passed.\n";
