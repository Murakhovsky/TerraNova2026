<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

final class ArchitectureGraphVocabulary
{
    public const TYPE_KERNEL = 'kernel';
    public const TYPE_DOMAIN = 'domain';
    public const TYPE_CAPABILITY = 'capability';
    public const TYPE_EVENT = 'event';
    public const TYPE_ACTION = 'action';
    public const TYPE_AGENT = 'agent';
    public const TYPE_SERVICE = 'service';
    public const TYPE_HANDLER = 'handler';
    public const TYPE_EXTENSION_POINT = 'extension_point';

    public const REL_CONTAINS = 'contains';
    public const REL_DEPENDS_ON = 'depends_on';
    public const REL_OWNS = 'owns';
    public const REL_CONTRIBUTES = 'contributes';
    public const REL_CONTRIBUTES_TO = 'contributes_to';
    public const REL_HANDLED_BY = 'handled_by';
    public const REL_PROPOSES = 'proposes';

    private function __construct()
    {
    }
}
