<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class GrowthSignalFeed
{
    public function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly string $name,
        public readonly string $url,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $signalType,
        public readonly float $confidence,
        private bool $enabled=true,
    ) {
        foreach([
            'id'=>$id,'name'=>$name,'url'=>$url,'subjectType'=>$subjectType,
            'subjectId'=>$subjectId,'signalType'=>$signalType,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth SignalFeed '.$field.' is required.');
        }
        if(mb_strlen($id)>80||mb_strlen($name)>160||mb_strlen($url)>1000||mb_strlen($subjectType)>80||
            mb_strlen($subjectId)>191||mb_strlen($signalType)>120){
            throw new InvalidArgumentException('Growth SignalFeed field exceeds its limit.');
        }
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||trim((string)($parts['host']??''))===''){
            throw new InvalidArgumentException('Growth SignalFeed URL must be an absolute HTTPS URL.');
        }
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])){
            throw new InvalidArgumentException('Growth SignalFeed URL must not contain credentials or fragment.');
        }
        if(isset($parts['port'])&&(int)$parts['port']!==443){
            throw new InvalidArgumentException('Growth SignalFeed URL may use only the default HTTPS port.');
        }
        if($confidence<0.0||$confidence>1.0){
            throw new InvalidArgumentException('Growth SignalFeed confidence must be between 0 and 1.');
        }
    }

    public function enabled():bool{return $this->enabled;}
    public function enable():void{$this->enabled=true;}
    public function disable():void{$this->enabled=false;}

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'feed_id'=>$this->id,'organization_id'=>$this->organizationId->value(),
            'name'=>$this->name,'url'=>$this->url,'subject_type'=>$this->subjectType,
            'subject_id'=>$this->subjectId,'signal_type'=>$this->signalType,
            'confidence'=>$this->confidence,'enabled'=>$this->enabled,
        ];
    }
}
