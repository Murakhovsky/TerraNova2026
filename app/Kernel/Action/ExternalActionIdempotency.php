<?php
declare(strict_types=1);

namespace Kernel\Action;

use InvalidArgumentException;

final class ExternalActionIdempotency
{
    public static function resolve(Action $action): string
    {
        $key = trim($action->idempotencyKey ?? $action->id);
        if ($key === '') {
            throw new InvalidArgumentException(sprintf(
                'External action %s requires a stable idempotency key.',
                $action->type,
            ));
        }

        return $key;
    }
}
