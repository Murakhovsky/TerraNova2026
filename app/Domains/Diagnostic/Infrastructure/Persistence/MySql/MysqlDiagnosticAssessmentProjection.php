<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Infrastructure\Persistence\MySql;

use Domains\Diagnostic\Application\Contract\DiagnosticAssessmentProjectionInterface;
use Domains\Diagnostic\Methodology\Result\DiagnosticResult;
use PDO;

final readonly class MysqlDiagnosticAssessmentProjection implements DiagnosticAssessmentProjectionInterface
{
    public function __construct(private PDO $db) {}

    public function replace(string $organizationId, string $sessionId, DiagnosticResult $result): void
    {
        $delete = $this->db->prepare('DELETE FROM diagnostic_assessment_results WHERE organization_id = :organization_id AND session_id = :session_id');
        $delete->execute(['organization_id' => $organizationId, 'session_id' => $sessionId]);
        if ($result->assessments === []) return;
        $insert = $this->db->prepare('INSERT INTO diagnostic_assessment_results (organization_id, session_id, criterion_id, score, coverage, confidence, applicable) VALUES (:organization_id, :session_id, :criterion_id, :score, :coverage, :confidence, :applicable)');
        foreach ($result->assessments as $assessment) {
            $insert->execute([
                'organization_id' => $organizationId,
                'session_id' => $sessionId,
                'criterion_id' => $assessment->criterionId,
                'score' => $assessment->score,
                'coverage' => $assessment->coverage->ratio,
                'confidence' => $assessment->confidence,
                'applicable' => $assessment->applicable ? 1 : 0,
            ]);
        }
    }
}
