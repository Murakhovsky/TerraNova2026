<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final readonly class ModuleInstallation
{
    public const INSTALLED = 'INSTALLED';
    public const UNINSTALLED = 'UNINSTALLED';

    public function __construct(
        public string $organizationId,
        public string $moduleId,
        public string $status,
        public string $installedVersion,
        public string $schemaVersion,
    ) {
        if (!in_array($this->status, [self::INSTALLED, self::UNINSTALLED], true)) {
            throw new InvalidArgumentException(sprintf('Invalid module installation status: %s.', $this->status));
        }
        VersionConstraint::assertVersion($this->installedVersion, 'installed module version');
        VersionConstraint::assertVersion($this->schemaVersion, 'installed module schema version');
    }

    public function isInstalled(): bool
    {
        return $this->status === self::INSTALLED;
    }
}
