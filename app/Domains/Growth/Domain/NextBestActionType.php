<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum NextBestActionType:string
{
    case Ignore='ignore';
    case Monitor='monitor';
    case ConnectLinkedIn='connect_linkedin';
    case SendEmail='send_email';
    case Call='call';
    case OfferDiagnostic='offer_diagnostic';
    case SendCaseStudy='send_case_study';
    case AskIntroduction='ask_introduction';
    case InviteWebinar='invite_webinar';
    case CreateReport='create_report';

    public function allowsChannel(EngagementChannel $channel):bool
    {
        return match($this){
            self::Ignore,self::Monitor=>$channel===EngagementChannel::None,
            self::ConnectLinkedIn=>$channel===EngagementChannel::LinkedIn,
            self::SendEmail=>$channel===EngagementChannel::Email,
            self::Call=>$channel===EngagementChannel::Phone,
            self::CreateReport=>$channel===EngagementChannel::Internal,
            self::OfferDiagnostic,self::SendCaseStudy,self::AskIntroduction,self::InviteWebinar=>in_array(
                $channel,[EngagementChannel::Email,EngagementChannel::Phone,EngagementChannel::LinkedIn,EngagementChannel::Manual],true
            ),
        };
    }

    /** @return list<string> */
    public static function values():array
    {
        return array_map(static fn(self $case):string=>$case->value,self::cases());
    }
}
