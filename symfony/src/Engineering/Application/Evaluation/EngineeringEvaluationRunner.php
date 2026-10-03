<?php
declare(strict_types=1);

namespace App\Engineering\Application\Evaluation;

use App\Engineering\Application\Agent\EngineeringAgentOutputValidationException;
use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Domain\Agent\AgentRole;
use RuntimeException;
use Throwable;

final readonly class EngineeringEvaluationRunner
{
    public function __construct(
        private EngineeringAgentOutputValidator $validator = new EngineeringAgentOutputValidator(),
    ) {}

    /** @return array{dataset:string,cases:int,passed:int,failed:int,accuracy:float,results:list<array<string,mixed>>} */
    public function run(array $dataset): array
    {
        $name = trim((string) ($dataset['dataset'] ?? ''));
        $version = trim((string) ($dataset['version'] ?? ''));
        $agentVersion = trim((string) ($dataset['agent_version'] ?? ''));
        $promptVersion = trim((string) ($dataset['prompt_version'] ?? ''));
        $schemaVersion = trim((string) ($dataset['schema_version'] ?? ''));
        $cases = is_array($dataset['cases'] ?? null) ? $dataset['cases'] : [];

        if ($name === '' || $version === '' || $agentVersion === '' || $promptVersion === '' || $schemaVersion === '') {
            throw new RuntimeException('Engineering evaluation dataset must be version-correlated.');
        }
        if ($cases === []) throw new RuntimeException('Engineering evaluation dataset has no cases.');

        $results = [];
        $passed = 0;

        foreach ($cases as $case) {
            if (!is_array($case)) continue;
            $id = trim((string) ($case['id'] ?? ''));
            $role = AgentRole::tryFrom((string) ($case['role'] ?? ''));
            $output = is_array($case['output'] ?? null) ? $case['output'] : [];
            $expectedValid = (bool) ($case['expected_valid'] ?? false);

            if ($id === '' || !$role instanceof AgentRole) {
                throw new RuntimeException('Engineering evaluation case metadata is invalid.');
            }

            $actualValid = true;
            $error = null;
            try {
                $this->validator->validate($role, $output);
            } catch (EngineeringAgentOutputValidationException $exception) {
                $actualValid = false;
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                $actualValid = false;
                $error = $exception->getMessage();
            }

            $casePassed = $actualValid === $expectedValid;
            if ($casePassed) ++$passed;

            $results[] = [
                'id' => $id,
                'role' => $role->value,
                'expected_valid' => $expectedValid,
                'actual_valid' => $actualValid,
                'passed' => $casePassed,
                'error' => $error,
            ];
        }

        $count = count($results);
        return [
            'dataset' => $name.'@'.$version,
            'cases' => $count,
            'passed' => $passed,
            'failed' => $count - $passed,
            'accuracy' => $count > 0 ? round($passed / $count, 4) : 0.0,
            'results' => $results,
        ];
    }
}
