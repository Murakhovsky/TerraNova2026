<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum PipelineStage: string
{
    use HasStringValues;

    case New = 'new';
    case Qualification = 'qualification';
    case NeedDefined = 'need_defined';
    case Matching = 'matching';
    case Viewing = 'viewing';
    case Negotiation = 'negotiation';
    case Deal = 'deal';
    case Aftercare = 'aftercare';
    case Repeat = 'repeat';
    case Paused = 'paused';
    case Lost = 'lost';
}
