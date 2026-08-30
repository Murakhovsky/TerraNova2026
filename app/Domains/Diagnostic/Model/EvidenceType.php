<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

enum EvidenceType: string
{
    use HasStringValues;

    case Document = 'document';
    case Interview = 'interview';
    case SystemData = 'system_data';
    case Observation = 'observation';
    case Survey = 'survey';
    case ExternalSource = 'external_source';
}
