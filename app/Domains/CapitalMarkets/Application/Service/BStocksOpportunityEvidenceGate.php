<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStrength;
/** Fail closed for historical bStock seed identity until primary evidence is independently reviewed. */
final class BStocksOpportunityEvidenceGate
{
    /** @return string BLOCKED|REFERENCE_ONLY|VERIFIED_ROUTE|LEGACY */
    public function admit(EconomicRelationship $link,string $hypothesis):string
    {
        if (!in_array($hypothesis,['H1','H2'],true))return 'BLOCKED';
        $a=$link->sourceInstrument->value();$b=$link->targetInstrument->value();
        $seed=str_starts_with($a,'cm_bstock_')||str_starts_with($a,'cm_underlying_')
            ||str_starts_with($b,'cm_bstock_')||str_starts_with($b,'cm_underlying_');
        if (!$seed)return 'LEGACY';
        $m=$link->metadata;$hash=$m['issuer_evidence_sha256']??null;$tier=$m['evidence_tier']??null;
        if (!in_array($link->strength,[EconomicRelationshipStrength::Exact,EconomicRelationshipStrength::Direct],true)
            || !in_array($tier,['E3','E4'],true)
            || !is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1
            || ($m['underlying_verified']??null)!==true
            || ($m['unit_multiplier_verified']??null)!==true
            || ($m['corporate_action_version_verified']??null)!==true)return 'BLOCKED';
        if ($tier==='E3')return $hypothesis==='H1'?'REFERENCE_ONLY':'BLOCKED';
        if (($m['route_verified']??null)!==true
            || ($m['fees_verified']??null)!==true
            || ($m['conversion_rights_verified']??null)!==true)return $hypothesis==='H1'?'REFERENCE_ONLY':'BLOCKED';
        return 'VERIFIED_ROUTE';
    }
}
