<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;

/** Reviewed document SHA is necessary but never sufficient to establish issuer E4. */
final readonly class InstrumentPoolIssuerE4Policy
{
    /** @param array<string,mixed> $metadata */
    public static function valid(array $metadata,string $approvedDigest):bool
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$approvedDigest)!==1
            ||($metadata['evidence_tier']??null)!=='E4'
            ||!is_string($metadata['issuer_evidence_sha256']??null)
            ||!hash_equals($approvedDigest,$metadata['issuer_evidence_sha256']))return false;
        foreach(['underlying_verified','unit_multiplier_verified',
            'corporate_action_version_verified','route_verified','fees_verified',
            'conversion_rights_verified'] as $field){
            if(($metadata[$field]??false)!==true)return false;
        }
        return true;
    }
}
