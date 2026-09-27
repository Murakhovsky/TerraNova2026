<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthResponseIntent:string
{
    case Interested='interested';
    case Question='question';
    case MeetingRequest='meeting_request';
    case Objection='objection';
    case NotInterested='not_interested';
    case Unsubscribe='unsubscribe';
    case Referral='referral';
    case WrongPerson='wrong_person';
    case OutOfOffice='out_of_office';
    case Other='other';

    /** @return list<string> */
    public static function values():array{return array_map(static fn(self $case):string=>$case->value,self::cases());}
}
