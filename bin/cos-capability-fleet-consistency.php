#!/usr/bin/env php
<?php
declare(strict_types=1);

use Kernel\Module\CapabilityFleetConsistencyGate;

$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';

try {
    $options=getopt('', ['manifest:', 'witness-dir:']);
    if (!is_array($options)
        || !is_string($options['manifest'] ?? null)
        || !is_string($options['witness-dir'] ?? null)) {
        throw new DomainException('Usage: --manifest=/path/manifest.json --witness-dir=/path/evidence');
    }
    $load=static function(string $path):array {
        if (!is_file($path) || is_link($path) || filesize($path)>1048576) {
            throw new DomainException('Missing, symlinked, or oversized fleet evidence.');
        }
        $data=json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new DomainException('Fleet input must be a JSON object.');
        }
        return $data;
    };
    $manifest=$load($options['manifest']);
    $dir=realpath($options['witness-dir']);
    if ($dir===false || !is_dir($dir)) {
        throw new DomainException('Fleet witness directory is missing.');
    }
    $files=glob($dir.'/*.json') ?: [];
    if (count($files)>100) {
        throw new DomainException('Too many fleet evidence files.');
    }
    $witnesses=[];
    foreach ($files as $file) $witnesses[]=$load($file);
    $result=(new CapabilityFleetConsistencyGate())->inspect($manifest,$witnesses);
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT
        |JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
    exit($result['consistent'] ? 0 : 2);
} catch (Throwable $error) {
    echo json_encode([
        'format'=>'cos-capability-fleet-consistency-result-v1',
        'status'=>'FAIL_CLOSED',
        'consistent'=>false,
        'production_certified'=>false,
        'error_type'=>get_class($error),
    ],JSON_THROW_ON_ERROR),"\n";
    exit(1);
}
