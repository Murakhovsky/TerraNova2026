<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$registryPath=$root.'/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php';
if(!is_file($registryPath))throw new RuntimeException('PageArchetypeRegistry is missing.');
$registry=(string)file_get_contents($registryPath);

$start=strpos($registry,'PageArchetype::OperationalQueue');
$end=$start===false?false:strpos($registry,'PageArchetype::Collection',$start);
if($start===false||$end===false)throw new RuntimeException('Operational Queue registry definition is missing.');
$definition=substr($registry,$start,$end-$start);

foreach(["['PageHeader', 'EntityList']","'KpiStrip'","'FilterBar'","'ActionBar'","'EmptyState'","'ErrorState'"] as $marker){
    if(!str_contains($definition,$marker))throw new RuntimeException('Operational Queue pattern contract incomplete: '.$marker);
}

echo "Wave 13 Operational Queue pattern contract passed.\n";
