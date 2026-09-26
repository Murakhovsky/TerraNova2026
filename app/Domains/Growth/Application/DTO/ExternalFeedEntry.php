<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExternalFeedEntry
{
    public function __construct(
        public string $externalId,
        public string $title,
        public string $link,
        public string $summary,
        public ?string $author,
        public DateTimeImmutable $occurredAt,
    ) {
        foreach(['externalId'=>$externalId,'title'=>$title,'link'=>$link] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth ExternalFeedEntry '.$field.' is required.');
        }
        if(mb_strlen($externalId)>1000||mb_strlen($title)>1000||mb_strlen($link)>2000||mb_strlen($summary)>4000){
            throw new InvalidArgumentException('Growth ExternalFeedEntry field exceeds its limit.');
        }
        if($author!==null&&mb_strlen($author)>500)throw new InvalidArgumentException('Growth ExternalFeedEntry author exceeds its limit.');
    }
}
