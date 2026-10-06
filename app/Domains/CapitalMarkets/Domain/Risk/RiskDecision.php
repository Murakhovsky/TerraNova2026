<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
enum RiskDecision:string { case Approve='APPROVE'; case ApproveWithLimit='APPROVE_WITH_LIMIT'; case Reject='REJECT'; case Stop='STOP'; }
