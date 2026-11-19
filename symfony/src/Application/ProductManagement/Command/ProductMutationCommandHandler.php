<?php
declare(strict_types=1);

namespace App\Application\ProductManagement\Command;

use DomainException;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Kernel\Application\Command\CommandHandlerInterface;

/** Domain use cases remain the only local writers; no parallel executor. */
final readonly class ProductMutationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductManagementService $products,
        private PricingService $prices,
        private CsvProductImportService $imports,
    ) {}

    /** @return array<string,mixed> */
    public function __invoke(ProductMutationCommand $command): array
    {
        $org = $command->organization->value();
        $in = $command->input;
        $key = $command->idempotencyKey;
        $actor = $command->actorId;
        $trace = $command->correlationId;
        return match ($command->operation) {
            'product.update' => $this->products->updateProduct(
                $org,(string)$in['product_id'],$in,(int)$in['expected_revision'],$key,$actor,$trace,
            ),
            'product.archive' => $this->products->archiveProduct(
                $org,(string)$in['product_id'],(int)$in['expected_revision'],$key,$actor,$trace,
            ),
            'variant.update' => $this->products->updateVariant(
                $org,(string)$in['variant_id'],$in,(int)$in['expected_revision'],$key,$actor,$trace,
            ),
            'variant.archive' => $this->products->archiveVariant(
                $org,(string)$in['variant_id'],(int)$in['expected_revision'],$key,$actor,$trace,
            ),
            'variant.create' => $this->products->createVariant(
                $org, (string) $in['product_id'], (string) $in['sku'],
                (string) ($in['uom'] ?? 'pcs'), (bool) ($in['track_inventory'] ?? true),
                $key, $actor, $trace,
            ),
            'category.create' => $this->products->createCategory(
                $org,(string)$in['name'],(string)$in['slug'],isset($in['parent_id'])?(string)$in['parent_id']:null,
                $key,$actor,$trace,
            ),
            'category.assign' => $this->products->assignCategory(
                $org,(string)$in['product_id'],(string)$in['category_id'],$key,$actor,$trace,
            ),
            'location.create' => $this->products->createLocation(
                $org, (string) $in['code'], (string) $in['name'], $key, $actor, $trace,
            ),
            'stock.receive' => $this->products->recordMovement(
                $org, (string) $in['variant_id'], (string) $in['location_id'],
                (string) $in['quantity'], 'receipt', (string) $in['source_ref'],
                $key, $actor, $trace,
            ),
            'stock.issue' => $this->products->recordMovement(
                $org, (string) $in['variant_id'], (string) $in['location_id'],
                '-' . (string) $in['quantity'], 'issue', (string) $in['source_ref'],
                $key, $actor, $trace,
            ),
            'import.preview' => $this->imports->preview(
                $org,(string)$in['csv'],$actor,$key,
            ),
            'price.base.set' => $this->prices->set(
                $org, (string) $in['variant_id'], (string) $in['currency'],
                (string) $in['amount'], $key, $actor, $trace,
            ),
            default => throw new DomainException('Product Management command not supported.'),
        };
    }
}
