<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$compose=(string)file_get_contents($root.'/docker-compose.yml');
$runtime=(string)file_get_contents($root.'/.github/workflows/runtime.yml');
$doc=(string)file_get_contents($root.'/docs/04-domains/capital_markets/streaming-all-provider-lease-sweeper.md');
foreach(['cm-stream-sweeper:', 'profiles: [cm-stream-sweeper]',
    'COS_CM_STREAM_SWEEPER_ENABLED: "1"',
    'stream:sweep-expired --limit=100','restart: unless-stopped'] as $term){
    if(!str_contains($compose,$term))throw new RuntimeException('Optional sweeper profile missing '.$term);
}
if(!str_contains($runtime,'docker compose --profile cm-stream-sweeper config --quiet')){
    throw new RuntimeException('Optional source monitor Compose profile is not validated by runtime CI.');
}
if(!str_contains($doc,'docker compose --profile cm-stream-sweeper up -d cm-stream-sweeper')){
    throw new RuntimeException('Missing explicit deployment instruction.');
}
echo "Capital Markets opt-in streaming lease monitor profile passed.\n";
