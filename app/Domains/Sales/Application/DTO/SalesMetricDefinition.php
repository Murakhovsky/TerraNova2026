<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

final readonly class SalesMetricDefinition
{
    /**
     * @param list<string> $filters
     * @param list<string> $dataQualityRequirements
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $businessDefinition,
        public string $numerator,
        public string $denominator,
        public string $timeBasis,
        public array $filters,
        public string $currencyBehavior,
        public array $dataQualityRequirements,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'business_definition' => $this->businessDefinition,
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
            'time_basis' => $this->timeBasis,
            'filters' => $this->filters,
            'currency_behavior' => $this->currencyBehavior,
            'data_quality_requirements' => $this->dataQualityRequirements,
        ];
    }
}
