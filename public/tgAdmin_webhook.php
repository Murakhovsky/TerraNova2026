<?php
declare(strict_types=1);

// Keep the legacy public URL while routing it through the secured webhook controller.
$_SERVER['REQUEST_URI'] = '/TgAdmin/webhook';
require __DIR__ . '/../app/bootstrap_tg.php';
