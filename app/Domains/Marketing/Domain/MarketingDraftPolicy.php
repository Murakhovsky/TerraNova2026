<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DomainException;

final class MarketingDraftPolicy
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function validate(array $input): array
    {
        foreach (['organization_id','tenant_id','owner_id','campaign_id','status','revision','provider_campaign_id'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw new DomainException('Domain identity or lifecycle state must not come from caller input.');
            }
        }
        foreach (['name','business_objective','brief'] as $field) {
            if(array_key_exists($field,$input) && !is_string($input[$field])) {
                throw new DomainException('Campaign text fields must be strings.');
            }
        }
        $name = trim($input['name'] ?? '');
        $objective = trim($input['business_objective'] ?? '');
        $brief = trim($input['brief'] ?? '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 180) throw new DomainException('Campaign name must be 3 to 180 characters.');
        if ($objective === '' || mb_strlen($objective) > 250) throw new DomainException('Business objective must be 1 to 250 characters.');
        if (mb_strlen($brief) > 5000) throw new DomainException('Brief is too long.');
        $refs = $input['product_refs'] ?? [];
        if (!is_array($refs) || count($refs) > 50 || !array_is_list($refs)) throw new DomainException('Invalid Product references.');
        $refs = array_values(array_unique(array_map(static function(mixed $v): string {
            if (!is_string($v) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $v)) {
                throw new DomainException('Invalid Product reference.');
            }
            return $v;
        }, $refs)));
        return ['name'=>$name,'business_objective'=>$objective,'brief'=>$brief,'product_refs'=>$refs];
    }
}
