<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
enum OpportunityStatus:string { case Candidate='CANDIDATE'; case Validating='VALIDATING'; case Valid='VALID'; case Rejected='REJECTED'; case Approved='APPROVED'; case Reserved='RESERVED'; case Executing='EXECUTING'; case Executed='EXECUTED'; case Failed='FAILED'; case Expired='EXPIRED'; }
