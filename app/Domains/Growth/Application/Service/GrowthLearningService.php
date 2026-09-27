<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use Domains\Growth\Application\Contract\GrowthLearningBoundary;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use InvalidArgumentException;

final readonly class GrowthLearningService implements GrowthLearningBoundary
{
    public function __construct(
        private GrowthRepositoryInterface $growth,
        private GrowthLearningRepositoryInterface $learning,
    ) {}

    public function learningBrief(string $organizationId,string $candidateId):array
    {
        $candidateId=trim($candidateId);
        if($candidateId===''||mb_strlen($candidateId)>80)throw new InvalidArgumentException('candidateId is invalid.');
        return [
            'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Growth candidate was not found.'),
            'summary'=>$this->learning->outcomeSummary($organizationId,$candidateId),
            'outcomes'=>$this->learning->outcomesForCandidate($organizationId,$candidateId,100),
        ];
    }
}
