<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Model\DataProvenance;
use Domains\Property\Model\ExternalReference;
use Domains\Property\Model\PropertyIdentity;
use Domains\Property\Model\PropertyIdentityResolutionCandidate;
use Domains\Property\Model\PropertyIdentityResolutionDecision;
use Domains\Property\Model\PropertyIdentityResolver;
use Domains\Property\Model\PropertyIdentitySignals;
use Domains\Property\Model\PropertyPartyRelation;
use Domains\Property\Model\PropertyPartyRelationType;
use Domains\Property\Model\PropertySource;
use Domains\Property\Model\PropertySourceType;
use Domains\Property\Model\PropertyVerificationStatus;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$identity = new PropertyIdentity('org-a', 'TN-392');
$developerSource = new PropertySource(
    'org-a',
    'SRC-DEV-XYZ',
    PropertySourceType::from(PropertySourceType::DEVELOPER),
    'developer_xyz_api',
    0.85,
    'CRM:organization:DEV-XYZ',
);
$ownerSource = new PropertySource(
    'org-a',
    'SRC-OWNER-1',
    PropertySourceType::from(PropertySourceType::OWNER),
    'owner_submission',
    0.60,
    'CRM:person:P-883',
);
$documentSource = new PropertySource(
    'org-a',
    'SRC-DOC-1',
    PropertySourceType::from(PropertySourceType::MANUAL),
    'technical_passport',
    1.0,
);

$importedAt = new DateTimeImmutable('2026-09-14T13:00:00+03:00');
$external = new ExternalReference($identity, $developerSource->sourceId, $developerSource->sourceSystem, 'APT-5-43', $importedAt);
$assert($external->lookupKey() === 'org-a:developer_xyz_api:APT-5-43', 'External reference lookup key drifted.');

$developerArea = new DataProvenance(
    $identity,
    'specs.residential.total_area',
    $developerSource->sourceId,
    64.2,
    $importedAt,
    0.85,
    new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
    null,
    PropertyVerificationStatus::from(PropertyVerificationStatus::CONFIRMED),
);
$ownerArea = new DataProvenance(
    $identity,
    'specs.residential.total_area',
    $ownerSource->sourceId,
    66.0,
    $importedAt,
    0.60,
);
$passportArea = new DataProvenance(
    $identity,
    'specs.residential.total_area',
    $documentSource->sourceId,
    64.7,
    $importedAt,
    1.0,
    new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
    null,
    PropertyVerificationStatus::from(PropertyVerificationStatus::VERIFIED),
);
$assert($developerArea->observedValue === 64.2 && $ownerArea->observedValue === 66.0, 'Property must retain conflicting observations instead of overwriting history.');
$assert($passportArea->status()->value === PropertyVerificationStatus::VERIFIED, 'Verified provenance must retain verification status.');

$ownerRelation = new PropertyPartyRelation(
    $identity,
    'CRM:person:P-883',
    PropertyPartyRelationType::from(PropertyPartyRelationType::OWNER),
    'active',
    new DateTimeImmutable('2025-01-01T00:00:00+02:00'),
    null,
    $documentSource->sourceId,
    1.0,
);
$assert($ownerRelation->isActiveAt($importedAt), 'Temporal owner relation should be active at import time.');
$assert(!property_exists($ownerRelation, 'phone') && !property_exists($ownerRelation, 'email'), 'Property Party relation must not duplicate CRM contact data.');

$resolver = new PropertyIdentityResolver();
$candidateSignals = new PropertyIdentitySignals(
    ['developer_xyz_api:APT-5-43'],
    '4610137500:01:001:0043',
    'ua/lviv-region/briukhovychi/lisova/12',
    'TN-LH',
    'TN-LH-B5',
    '43',
    64.7,
);
$candidate = new PropertyIdentityResolutionCandidate($identity, $candidateSignals);

$exactExternal = $resolver->resolve('org-a', new PropertyIdentitySignals(
    ['developer_xyz_api:APT-5-43'],
    totalArea: 64.2,
), [$candidate]);
$assert($exactExternal->decision->value === PropertyIdentityResolutionDecision::MERGE, 'Exact external reference must resolve to MERGE.');
$assert($exactExternal->candidateIdentity?->equals($identity) === true && $exactExternal->score === 1.0, 'Exact external identity must resolve the canonical PropertyIdentity.');

$structural = $resolver->resolve('org-a', new PropertyIdentitySignals(
    addressCanonicalKey: 'ua/lviv-region/briukhovychi/lisova/12',
    developmentAssetId: 'TN-LH',
    buildingAssetId: 'TN-LH-B5',
    unitLabel: '43',
    totalArea: 64.3,
), [$candidate]);
$assert($structural->decision->value === PropertyIdentityResolutionDecision::MERGE, 'Strong structural identity must resolve to MERGE.');

$review = $resolver->resolve('org-a', new PropertyIdentitySignals(
    addressCanonicalKey: 'ua/lviv-region/briukhovychi/lisova/12',
    totalArea: 64.3,
), [$candidate]);
$assert($review->decision->value === PropertyIdentityResolutionDecision::REVIEW, 'Address plus close area must require REVIEW rather than automatic merge.');

$unrelated = $resolver->resolve('org-a', new PropertyIdentitySignals(
    addressCanonicalKey: 'ua/kyiv/other/1',
    totalArea: 91.0,
), [$candidate]);
$assert($unrelated->decision->value === PropertyIdentityResolutionDecision::CREATE, 'Unrelated intake must resolve to CREATE.');

$foreignTenant = $resolver->resolve('org-b', $candidateSignals, [$candidate]);
$assert($foreignTenant->decision->value === PropertyIdentityResolutionDecision::CREATE, 'Identity resolution must never merge across tenants.');

$reject = PropertyIdentityResolutionDecision::from(PropertyIdentityResolutionDecision::REJECT);
$assert($reject->value === 'reject', 'REJECT must remain an explicit moderation outcome.');

echo "Property V0.4 identity, provenance and relations: OK\n";
