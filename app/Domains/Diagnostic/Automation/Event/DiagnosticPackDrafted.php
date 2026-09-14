<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Event;

use DateTimeImmutable;
use Domains\Diagnostic\Model\DiagnosticPack;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DiagnosticPackDrafted
{
    public const TYPE = 'diagnostic.pack.drafted';

    public static function create(string $id, string $organizationId, DiagnosticPack $pack, EventMetadata $metadata, DateTimeImmutable $at): DomainEvent
    {
        return new DomainEvent($id, $organizationId, self::TYPE, 'diagnostic_pack', $pack->id() . ':' . $pack->version(), [
            'pack_id' => $pack->id(), 'version' => $pack->version(), 'target_domain' => $pack->targetDomain(),
            'content_hash' => $pack->contentHash(),
        ], $metadata, $at);
    }
}
