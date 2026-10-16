<?php
declare(strict_types=1);

/** Read-only reproducible static audit of current checkout. */
$root=dirname(__DIR__);
require_once $root.'/app/Platform/Workflow/Service/WorkflowExecutionAuthorityInventory.php';
$report=\Platform\Workflow\Service\WorkflowExecutionAuthorityInventory::inspect($root);
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
exit($report['errors']===[]?0:1);
