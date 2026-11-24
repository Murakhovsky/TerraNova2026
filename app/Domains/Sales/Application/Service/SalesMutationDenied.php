<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

/** Authentication/ownership rejection, distinct from native business errors. */
final class SalesMutationDenied extends \DomainException {}
