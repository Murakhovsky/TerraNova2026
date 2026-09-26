<?php
declare(strict_types=1);
namespace Domains\Growth\Domain;
use InvalidArgumentException;
final readonly class OutreachSequencePolicy
{
    public array $allowedChannels;
    public function __construct(public bool $enabled,array $allowedChannels,public int $maxTouches,public int $followUpDelayHours,public int $maxAdvancesPerRun)
    {
        $allowed=array_fill_keys(['email','linkedin','phone'],true);$channels=[];
        foreach($allowedChannels as $channel){if(!is_string($channel)||!isset($allowed[$channel]))throw new InvalidArgumentException('Outreach sequence channel is invalid.');$channels[$channel]=true;}
        if($channels===[])throw new InvalidArgumentException('Outreach sequence requires at least one allowed channel.');
        $normalizedChannels=array_keys($channels);
        sort($normalizedChannels,SORT_STRING);
        $this->allowedChannels=$normalizedChannels;
        if($maxTouches<1||$maxTouches>20)throw new InvalidArgumentException('Outreach sequence max touches must be between 1 and 20.');
        if($followUpDelayHours<1||$followUpDelayHours>8760)throw new InvalidArgumentException('Outreach sequence follow-up delay must be between 1 and 8760 hours.');
        if($maxAdvancesPerRun<1||$maxAdvancesPerRun>100)throw new InvalidArgumentException('Outreach sequence run limit must be between 1 and 100.');
    }
    public function allowsChannel(string $channel):bool{return in_array(strtolower(trim($channel)),$this->allowedChannels,true);}
    public function toArray():array{return ['enabled'=>$this->enabled,'allowed_channels'=>$this->allowedChannels,'max_touches'=>$this->maxTouches,'follow_up_delay_hours'=>$this->followUpDelayHours,'max_advances_per_run'=>$this->maxAdvancesPerRun];}
}
