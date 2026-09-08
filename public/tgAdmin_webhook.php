<?php
declare(strict_types=1);

// Legacy Telegram runtime is intentionally disabled.
// Keep this public endpoint as a stable tombstone so old webhook configuration
// cannot accidentally boot the legacy Telegram application.
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo 'Telegram runtime disabled';
