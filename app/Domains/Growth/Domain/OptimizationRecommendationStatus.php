<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum OptimizationRecommendationStatus:string
{
    case Proposed='proposed';
    case Accepted='accepted';
    case Dismissed='dismissed';
    case Materialized='materialized';
    case Stale='stale';
    case Superseded='superseded';
}
