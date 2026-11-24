<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Kernel\Module\CapabilityContract;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\KernelVersion;
$root=dirname(__DIR__,2);
$modules=(new ModuleDiscovery($root.'/app/Domains'))->discover();
$catalog=new ModuleCatalog($modules);
$catalog->assertCompatibility(KernelVersion::VERSION);
$definition=$catalog->definition('product_management');
if ($definition->manifest->enabledByDefault) throw new RuntimeException('PM must be tenant-disabled by default.');
$contracts=$definition->contributions->capabilityContracts;
if (count($contracts)!==75) throw new RuntimeException('Unexpected executable PM capability count.');
foreach($contracts as $contract) {
    if (!$contract instanceof CapabilityContract) throw new RuntimeException('Invalid PM contract.');
    foreach([$contract->inputSchema,$contract->outputSchema] as $path) {
        $json=json_decode((string)file_get_contents($root.'/'.$path),true,flags:JSON_THROW_ON_ERROR);
        if (($json['type']??null)!=='object') throw new RuntimeException('Invalid capability schema '.$path);
    }
}
echo "Product Management capabilities contract passed.\n";
