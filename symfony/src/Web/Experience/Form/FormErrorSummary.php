<?php

declare(strict_types=1);

namespace App\Web\Experience\Form;

use Symfony\Component\Form\FormInterface;

final class FormErrorSummary
{
    /** @return list<string> */
    public function messages(FormInterface $form): array
    {
        $messages = [];

        foreach ($form->getErrors(true, true) as $error) {
            $message = trim($error->getMessage());

            if ($message === '') {
                continue;
            }

            $origin = $error->getOrigin();
            if ($origin !== null && $origin !== $form && $origin->getName() !== '') {
                $message = self::humanize($origin->getName()) . ': ' . $message;
            }

            $messages[] = $message;
        }

        return array_values(array_unique($messages));
    }

    private static function humanize(string $name): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $name)) ?? $name;

        return ucfirst(trim($value));
    }
}
