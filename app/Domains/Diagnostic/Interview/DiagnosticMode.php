<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
enum DiagnosticMode:string { case QuickScan='QUICK_SCAN'; case DeepDiagnostic='DEEP_DIAGNOSTIC'; case FocusedDiagnostic='FOCUSED_DIAGNOSTIC'; case EvidenceValidation='EVIDENCE_VALIDATION'; case FollowUpDiagnostic='FOLLOW_UP_DIAGNOSTIC'; }
