<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Request;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

final readonly class JsonRequestMapper
{
    public function __construct(
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function map(Request $request, string $dtoClass): object
    {
        try {
            $dto = $this->serializer->deserialize((string) $request->getContent(), $dtoClass, 'json');
        } catch (Throwable $error) {
            throw new BadRequestHttpException('Invalid JSON request payload.', $error);
        }

        if (!is_object($dto)) {
            throw new BadRequestHttpException('Request payload must deserialize to an object.');
        }

        if (count($this->validator->validate($dto)) > 0) {
            throw new UnprocessableEntityHttpException('Request validation failed.');
        }

        return $dto;
    }
}
