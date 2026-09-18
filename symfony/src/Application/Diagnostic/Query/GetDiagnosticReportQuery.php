<?php
declare(strict_types=1); namespace App\Application\Diagnostic\Query;
use Kernel\Application\Query\QueryInterface; use Kernel\Shared\Domain\OrganizationId;
final readonly class GetDiagnosticReportQuery implements QueryInterface { public function __construct(public OrganizationId $organizationId,public string $sessionId){} }
