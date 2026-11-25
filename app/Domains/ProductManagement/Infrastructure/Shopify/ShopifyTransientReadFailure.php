<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Kernel\Execution\ClassifiedExecutionFailureInterface;
use Kernel\Execution\ExecutionFailureKind;

/** Keep read-only adapter compatibility while admitting bounded Kernel retries. */
final class ShopifyTransientReadFailure extends DomainException implements ClassifiedExecutionFailureInterface
{
    public function failureKind():ExecutionFailureKind{return ExecutionFailureKind::ExternalUnavailable;}
}
