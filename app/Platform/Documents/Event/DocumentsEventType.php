<?php
declare(strict_types=1);

namespace Platform\Documents\Event;

final class DocumentsEventType
{
    public const UPLOADED = 'documents.document.uploaded';
    public const ATTACHED = 'documents.document.attached';
    public const VERSION_CREATED = 'documents.version.created';
    public const GENERATED = 'documents.document.generated';
    public const SIGNATURE_REQUESTED = 'documents.signature.requested';
    public const SIGNED = 'documents.signature.signed';
    public const ARCHIVED = 'documents.document.archived';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::UPLOADED,
            self::ATTACHED,
            self::VERSION_CREATED,
            self::GENERATED,
            self::SIGNATURE_REQUESTED,
            self::SIGNED,
            self::ARCHIVED,
        ];
    }
}
