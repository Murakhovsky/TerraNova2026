<?php

declare(strict_types=1);

namespace App\Web\Experience\Data;

enum DataGridState: string
{
    case Ready = 'ready';
    case Loading = 'loading';
    case Empty = 'empty';
    case Error = 'error';
}
