<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
enum HypothesisStatus: string { case Open='OPEN'; case Supported='SUPPORTED'; case Confirmed='CONFIRMED'; case Rejected='REJECTED'; case Inconclusive='INCONCLUSIVE'; }
