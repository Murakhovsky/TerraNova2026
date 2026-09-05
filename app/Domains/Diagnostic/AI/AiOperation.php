<?php
declare(strict_types=1);
namespace Domains\Diagnostic\AI;

enum AiOperation: string
{
    case ConductInterviewTurn='conductInterviewTurn';
    case ExtractFacts='extractFacts';
    case DetectContradictions='detectContradictions';
    case GenerateHypotheses='generateHypotheses';
    case AnalyzeRootCause='analyzeRootCause';
    case GenerateRecommendations='generateRecommendations';
    case GenerateExecutiveSummary='generateExecutiveSummary';
}
