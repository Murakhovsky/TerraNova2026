<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

enum DiagnosticPackStatus: string
{
    use HasStringValues;

    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
