<?php
declare(strict_types=1);

namespace Platform\Workflow\Service;

/**
 * MySQL JSON may reorder object keys; hash immutable graph semantics.
 * All canonical Run writers and receipt readers must use this one algorithm.
 */
final readonly class WorkflowDefinitionFingerprint
{
    /** @param list<array<string,mixed>> $steps */
    public static function digest(array $steps): string
    {
        return hash('sha256', json_encode(self::normalize($steps),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function normalize(mixed $node): mixed
    {
        if (!is_array($node)) return $node;
        if (!array_is_list($node)) ksort($node, SORT_STRING);
        foreach ($node as $key => $value) $node[$key] = self::normalize($value);
        return $node;
    }
}
