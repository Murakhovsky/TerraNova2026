<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

enum ExperienceMode: string
{
    case Result = 'result';
    case Process = 'process';
    case Expert = 'expert';

    public function defaultLevel(): int
    {
        return match ($this) {
            self::Result => 0,
            self::Process => 1,
            self::Expert => 3,
        };
    }
}
