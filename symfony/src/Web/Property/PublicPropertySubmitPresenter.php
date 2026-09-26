<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PublicPropertySubmitViewModel;

final class PublicPropertySubmitPresenter
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $formData
     */
    public function present(
        array $data,
        array $formData,
        ?string $submissionStatus = null,
        ?string $error = null,
    ): PublicPropertySubmitViewModel {
        $types = is_array($data['types'] ?? null)
            ? array_values(array_filter($data['types'], 'is_array'))
            : [];

        return new PublicPropertySubmitViewModel(
            types: $types,
            formData: $this->scalarFormData($formData),
            yearMax: (int) date('Y') + 2,
            submissionStatus: $submissionStatus,
            error: $error,
        );
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function scalarFormData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $result[(string) $key] = $value;
            }
        }

        return $result;
    }
}
