<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Application\Contract;

/** Product-owned inventory operations within the caller's authorized native transaction.
 * Local stock only; no provider writes or external commitment inference. */
interface OrderInventoryPort
{
    public function reserveForOrder(string $org,string $product,string $variant,string $sku,?string $location,int $quantity,string $source,string $key,string $actor,string $correlation):array;
    public function settleOrderReservation(string $org,string $reservation,string $source,string $operation,int $quantity,string $key,string $actor,string $correlation):array;
}
