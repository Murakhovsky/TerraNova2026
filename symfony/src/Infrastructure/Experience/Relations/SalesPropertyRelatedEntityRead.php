<?php
declare(strict_types=1);

namespace App\Infrastructure\Experience\Relations;

use App\Application\Experience\Relations\Contract\RelatedEntityReadInterface;
use App\Application\Experience\Relations\RelatedEntityReference;
use App\Security\SalesRequestEntityAuthorizationInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Domains\Property\Contract\PropertyReferencePort;

/**
 * Sales owns the match, Property owns the Asset. Both must verify the same
 * organization. Never infer a relationship from a similar search term.
 */
final readonly class SalesPropertyRelatedEntityRead implements RelatedEntityReadInterface
{
    public function __construct(
        private ClientCaseReadModelFactoryInterface $cases,
        private PropertyReferencePort $properties,
        private SalesRequestEntityAuthorizationInterface $authorization,
    ) {}

    public function related(string $organizationId, string $entityType, string $entityId, int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        if ($entityType !== 'sales.deal' || preg_match('/^[1-9][0-9]{0,9}$/D', $entityId) !== 1) {
            return [];
        }
        $caseId = (int)$entityId;
        if (!$this->authorization->entity($organizationId,'deal',$caseId)) return [];
        $read = $this->cases->forOrganization($organizationId);
        if ($read->case($caseId) === null) {
            return [];
        }
        $related = [];
        $seen = [];
        foreach (array_slice($read->propertyMatches($caseId), 0, $limit) as $match) {
            if (!is_array($match)) continue;
            $legacyId = (int)($match['property_id'] ?? 0);
            if ($legacyId <= 0) continue;
            $presentation = $this->properties->getPropertyPresentation($organizationId, $legacyId);
            if (!is_array($presentation)) continue;
            $property = is_array($presentation['property'] ?? null) ? $presentation['property'] : [];
            $listing = is_array($presentation['listing'] ?? null) ? $presentation['listing'] : [];
            $assetId = trim((string)($property['asset_id'] ?? ''));
            if (preg_match('/^PROP-[A-F0-9]{20}$/D', $assetId) !== 1 || isset($seen[$assetId])) {
                continue;
            }
            $seen[$assetId] = true;
            $label = trim((string)($listing['title'] ?? $property['formatted_address'] ?? ''));
            $status = trim((string)($match['match_status'] ?? ''));
            $related[] = new RelatedEntityReference(
                type: 'property.asset', id: $assetId,
                label: $label === '' ? $assetId : $label,
                relation: 'sales.property_match',
                detail: $status !== '' ? $status : null,
            );
        }
        return $this->authorization->entity($organizationId,'deal',$caseId)?$related:[];
    }
}
