<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Support;

use InvalidArgumentException;
use JsonException;

/** The signed bytes, event identity and applied payload must describe one envelope. */
final class CrmSignedEnvelope
{
    public static function assertMatches(string $raw, array $payload, string $eventId, string $eventType): void
    {
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid signed CRM JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded) || $decoded !== $payload
            || !is_string($decoded['event_id'] ?? null) || $decoded['event_id'] !== $eventId
            || !is_string($decoded['event_type'] ?? null) || $decoded['event_type'] !== $eventType) {
            throw new InvalidArgumentException('CRM event identity and payload must match the signed body.');
        }
    }
}
