<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
enum PortfolioDecisionState:string { case Accept='ACCEPT'; case AcceptReducedSize='ACCEPT_REDUCED_SIZE'; case Hold='HOLD'; case Reject='REJECT'; case RebalanceFirst='REBALANCE_FIRST'; case ManualReview='MANUAL_REVIEW'; }
