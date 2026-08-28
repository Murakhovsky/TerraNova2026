<?php
declare(strict_types=1);

namespace Interfaces\Cli\Task;

use Kernel\Configuration\Service\ConfigurationProvisioner;
use Kernel\Configuration\Service\ConfigurationValidator;
use Kernel\Module\DomainModuleRegistry;
use Phalcon\Cli\Task;

final class ConfigTask extends Task
{
    public function validateAction(string $organizationId = 'default'): void
    {
        /** @var DomainModuleRegistry $registry */
        $registry = $this->getDI()->getShared('cosDomainRegistry');
        /** @var ConfigurationValidator $validator */
        $validator = $this->getDI()->getShared('cosConfigurationValidator');
        $result = ['domains' => 0, 'rules' => 0, 'policies' => 0];
        foreach ($registry->modules() as $module) {
            $rules = $module->rules($organizationId);
            $policies = $module->policies($organizationId);
            $validator->validate($module, $organizationId, $rules, $policies);
            $result['domains']++;
            $result['rules'] += count($rules);
            $result['policies'] += count($policies);
        }
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    public function provisionAction(string $organizationId = 'default', string $actorId = 'cli'): void
    {
        /** @var ConfigurationProvisioner $provisioner */
        $provisioner = $this->getDI()->getShared('cosConfigurationProvisioner');
        $result = $provisioner->provision($organizationId, $actorId);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
