<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

use InvalidArgumentException;

final class UIActionPlacement
{
    public const WORKSPACE = 'workspace';
    public const WORKSPACE_PRIMARY = 'workspace.primary';
    public const WORKSPACE_SECONDARY = 'workspace.secondary';
    public const DATA_GRID_ROW = 'datagrid.row';
    public const DATA_GRID_BULK = 'datagrid.bulk';
    public const CONTEXT_MENU = 'context_menu';
    public const COMMAND_PALETTE = 'command_palette';
    public const MOBILE_PRIMARY = 'mobile.primary';
    public const MOBILE_MENU = 'mobile.menu';
    public const AI_PROPOSAL = 'ai_proposal';
    public const NOTIFICATION = 'notification';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::WORKSPACE,
            self::WORKSPACE_PRIMARY,
            self::WORKSPACE_SECONDARY,
            self::DATA_GRID_ROW,
            self::DATA_GRID_BULK,
            self::CONTEXT_MENU,
            self::COMMAND_PALETTE,
            self::MOBILE_PRIMARY,
            self::MOBILE_MENU,
            self::AI_PROPOSAL,
            self::NOTIFICATION,
        ];
    }

    public static function assert(string $placement): string
    {
        $placement = trim($placement);

        if (!in_array($placement, self::all(), true)) {
            throw new InvalidArgumentException('Unsupported UIAction placement: ' . $placement);
        }

        return $placement;
    }

    public static function isMobile(string $placement): bool
    {
        return in_array($placement, [self::MOBILE_PRIMARY, self::MOBILE_MENU], true);
    }

    private function __construct()
    {
    }
}
