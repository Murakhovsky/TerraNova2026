<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
enum AssessmentStatus: string { case Pass='PASS'; case Fail='FAIL'; case Partial='PARTIAL'; case Unknown='UNKNOWN'; case NotApplicable='NOT_APPLICABLE'; }
