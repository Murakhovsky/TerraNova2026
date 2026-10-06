<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

enum ExecutionRecoveryAction:string
{
    case ExecuteFirstLeg='EXECUTE_FIRST_LEG';
    case ExecuteSecondLeg='EXECUTE_SECOND_LEG';
    case Compensate='COMPENSATE';
    case Account='ACCOUNT';
    case Complete='COMPLETE';
    case ManualIntervention='MANUAL_INTERVENTION';
}
