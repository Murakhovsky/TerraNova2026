<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

final readonly class ShellViewModel
{
    /**
     * @param list<ShellNavigationItem> $primaryNavigation
     * @param list<ShellNavigationItem> $utilityNavigation
     * @param list<ShellBreadcrumb> $breadcrumbs
     * @param list<ShellCommandItem> $commands
     */
    public function __construct(
        public string $title,
        public string $tenantLabel,
        public string $userLabel,
        public string $userInitials,
        public string $activeSection,
        public array $primaryNavigation,
        public array $utilityNavigation,
        public array $breadcrumbs,
        public array $commands,
        public int $notificationCount = 0,
        public int $activityCount = 0,
        public ShellConnectionState $connectionState = ShellConnectionState::Live,
        public bool $aiAvailable = true,
    ) {
    }
}
