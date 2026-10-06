<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Risk;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class TokenizedSecurityRiskProfile extends ValueObject
{
    public function __construct(
        public int $issuerRisk,
        public int $custodianRisk,
        public int $legalStructureRisk,
        public int $redemptionRisk,
        public int $transferabilityRisk,
        public int $collateralizationRisk,
        public int $smartContractRisk,
        public int $jurisdictionRisk,
        public int $economicEquivalenceRisk,
    ){
        foreach(get_object_vars($this) as $value){
            if(!is_int($value)||$value<0||$value>100){
                throw new InvalidArgumentException('Tokenized security risk dimensions must be integers in range 0..100.');
            }
        }
    }

    public function score():int
    {
        return intdiv(
            $this->issuerRisk+$this->custodianRisk+$this->legalStructureRisk+$this->redemptionRisk+
            $this->transferabilityRisk+$this->collateralizationRisk+$this->smartContractRisk+
            $this->jurisdictionRisk+$this->economicEquivalenceRisk,
            9
        );
    }
}
