<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DomainException;

/**
 * Source ownership cannot be inferred from a supplied correlation string.
 * Only the exact Action type and immutable business target may mint links.
 */
final class FederationOutcomeOriginContract
{
    private const TARGETS = [
        'capital_markets' => ['action_type' => 'capital_markets.research.result.record', 'target_type' => 'research_result'],
        'platform.documents' => ['action_type' => 'documents.signature.sign', 'target_type' => 'document_signature'],
    ];

    public static function assertTarget(
        string $domain, string $actionType, ?string $targetType,
        ?string $targetId, string $outcomeId,
    ): void {
        $expected = self::TARGETS[$domain] ?? null;
        if ($expected === null || $expected['action_type'] !== $actionType
            || $expected['target_type'] !== $targetType
            || $outcomeId === '' || $outcomeId !== $targetId) {
            throw new DomainException('Federation outcome must match the approved Domain Action target.');
        }
    }

    public static function canLink(string $domain): bool
    {
        return isset(self::TARGETS[$domain]);
    }

    public static function actionType(string $domain): ?string
    {
        return self::TARGETS[$domain]['action_type'] ?? null;
    }
}
