<?php
declare(strict_types=1);

namespace App\Web\Operations;

/** Non-authoritative navigation only; every resumed query still enforces tenant. */
final class OperationsHistoryCursor
{
    public static function decode(string $value): array
    {
        if ($value === '') return [];
        if (strlen($value) > 8192) throw new \InvalidArgumentException('Invalid history cursor.');
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) throw new \InvalidArgumentException('Invalid history cursor.');
        try { $cursor = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('Invalid history cursor.'); }
        if (!is_array($cursor)) throw new \InvalidArgumentException('Invalid history cursor.');
        foreach ($cursor as $kind=>$point) {
            if (!in_array($kind, OperationsSnapshot::SOURCES, true)
                || ($point !== null && (!is_array($point) || !isset($point['date'], $point['id'])
                    || !is_string($point['date']) || !is_string($point['id'])
                    || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $point['date'])
                    || $point['id'] === '' || strlen($point['id']) > 190))) {
                throw new \InvalidArgumentException('Invalid history cursor.');
            }
        }
        return $cursor;
    }

    public static function encode(array $cursor): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
