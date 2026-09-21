<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

enum UIActionDangerLevel: int
{
    case None = 0;
    case Caution = 1;
    case Destructive = 2;
    case Critical = 3;

    public function requiresConfirmation(): bool
    {
        return $this !== self::None;
    }

    public function requiresStepUp(): bool
    {
        return $this === self::Critical;
    }
}
