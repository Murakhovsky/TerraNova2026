<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DomainException;

/** Absence of an activated, verified Domain writer means unknown, NOT zero. */
final class FederationOutcomeSourceNotReady extends DomainException {}
