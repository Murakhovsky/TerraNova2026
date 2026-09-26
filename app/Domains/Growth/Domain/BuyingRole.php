<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum BuyingRole: string
{
    case Unknown = 'unknown';
    case Champion = 'champion';
    case EconomicBuyer = 'economic_buyer';
    case TechnicalBuyer = 'technical_buyer';
    case UserBuyer = 'user_buyer';
    case Procurement = 'procurement';
    case Legal = 'legal';
    case ExecutiveSponsor = 'executive_sponsor';
    case Influencer = 'influencer';
    case Blocker = 'blocker';

    /** @param list<string> $values @return list<self> */
    public static function fromStrings(array $values): array
    {
        $roles=[];
        foreach($values as $value){
            $role=self::tryFrom(strtolower(trim($value)));
            if($role===null)throw new \InvalidArgumentException('Unknown Growth buying role: '.$value);
            $roles[$role->value]=$role;
        }
        return array_values($roles);
    }
}
