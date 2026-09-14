<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
enum FactStatus: string { case Known='KNOWN'; case Unknown='UNKNOWN'; case Contradicted='CONTRADICTED'; case Stale='STALE'; case NotApplicable='NOT_APPLICABLE'; }
