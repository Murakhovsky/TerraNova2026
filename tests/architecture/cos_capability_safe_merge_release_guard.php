<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$workflow=file_get_contents($root.'/.github/workflows/runtime.yml');
if(!is_string($workflow))throw new RuntimeException('Runtime workflow missing.');
$guard="if: github.event_name == 'push' && github.ref == 'refs/heads/main'"
    ." && !contains(github.event.head_commit.message, '[capability-no-deploy]')";
if(substr_count($workflow,$guard)!==2) {
    throw new RuntimeException('Both GHCR publish and AWS dev deploy must honor explicit no-deploy merge marker.');
}
$publish=strpos($workflow,'  publish-runtime-images:');
$deploy=strpos($workflow,'  deploy-dev:');
if(!is_int($publish)||!is_int($deploy)||$deploy<=$publish) {
    throw new RuntimeException('Expected unchanged release jobs in canonical Runtime workflow.');
}
if(strpos(substr($workflow,$publish,$deploy-$publish),$guard)===false
    ||strpos(substr($workflow,$deploy),$guard)===false) {
    throw new RuntimeException('Release marker guard missing from publication/deploy path.');
}
if(!str_contains($workflow,"  push:\n    branches: [main]")
    ||!str_contains($workflow,"  pull_request:\n    branches: [main]")) {
    throw new RuntimeException('Merge marker must not suppress regular main push or PR CI.');
}
echo "Capability safe no-deploy merge marker covers both GHCR and AWS release jobs; main CI retained PASS.\n";
