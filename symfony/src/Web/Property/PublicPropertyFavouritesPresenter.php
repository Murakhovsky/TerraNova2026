<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PublicPropertyFavouritesViewModel;

final class PublicPropertyFavouritesPresenter
{
    /** @param array<string,mixed> $data */
    public function present(array $data, ?string $error = null): PublicPropertyFavouritesViewModel
    {
        $properties = is_array($data['properties'] ?? null)
            ? array_values(array_filter($data['properties'], 'is_array'))
            : [];

        return new PublicPropertyFavouritesViewModel(
            properties: $properties,
            error: $error,
        );
    }
}
