<?php
declare(strict_types=1);

// Backward-compatible entrypoint. The actual composition root is split by architectural role.
require APP_PATH . '/Bootstrap/InfrastructureServices.php';
require APP_PATH . '/Bootstrap/SalesServices.php';
require APP_PATH . '/Bootstrap/KernelServices.php';
