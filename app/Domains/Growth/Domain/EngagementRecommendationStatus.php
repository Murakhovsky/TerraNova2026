<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum EngagementRecommendationStatus:string
{
    case Proposed='proposed';
    case Accepted='accepted';
    case Dismissed='dismissed';
    case Superseded='superseded';
}
