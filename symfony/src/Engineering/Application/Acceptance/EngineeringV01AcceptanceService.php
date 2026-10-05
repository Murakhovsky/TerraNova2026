<?php
declare(strict_types=1);

namespace App\Engineering\Application\Acceptance;

use App\Engineering\Application\Audit\EngineeringAuditQueryInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Service\EngineeringStatusService;

final readonly class EngineeringV01AcceptanceService
{
    public function __construct(
        private EngineeringStatusService $status,
        private EngineeringAuditQueryInterface $audit,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringV01AcceptanceVerifier $verifier = new EngineeringV01AcceptanceVerifier(),
    ) {}

    /** @return array{feature_id:string,scenario:string,passed:bool,checks:list<array{id:string,passed:bool,detail:string}>,failures:list<string>} */
    public function verify(string $featureId, string $scenario): array
    {
        return $this->verifier->verify(
            featureId: $featureId,
            scenario: $scenario,
            status: $this->status->status($featureId),
            audit: $this->audit->forFeature($featureId),
            humanDecisions: $this->humanDecisions->historyForFeature($featureId),
        );
    }
}
