<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum OutcomeAttribution: string
{
    use HasStringValues;

    case Direct = 'DIRECT';
    case Assisted = 'ASSISTED';
    case Inferred = 'INFERRED';
    case Manual = 'MANUAL';
}
