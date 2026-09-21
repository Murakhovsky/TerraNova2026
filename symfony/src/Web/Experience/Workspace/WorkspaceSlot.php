<?php

declare(strict_types=1);

namespace App\Web\Experience\Workspace;

enum WorkspaceSlot: string
{
    case Header = 'header';
    case PrimaryActions = 'primary_actions';
    case Navigation = 'navigation';
    case Tabs = 'tabs';
    case Main = 'main';
    case Sidebar = 'sidebar';
    case Activity = 'activity';
    case Documents = 'documents';
    case Ai = 'ai';
    case Footer = 'footer';

    public function order(): int
    {
        return match ($this) {
            self::Header => 10,
            self::PrimaryActions => 20,
            self::Navigation => 30,
            self::Tabs => 40,
            self::Main => 50,
            self::Sidebar => 60,
            self::Activity => 70,
            self::Documents => 80,
            self::Ai => 90,
            self::Footer => 100,
        };
    }
}
