<?php
declare(strict_types=1);

use App\Http\Api\V1\Request\JsonRequestMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ApiV1MapperFixture
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name = '',
    ) {
    }
}

function expectRequestMapper(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "API v1 request mapper failed: {$message}\n");
        exit(1);
    }
}

$mapper = new JsonRequestMapper(
    new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
    Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
);

$dto = $mapper->map(Request::create('/api/v1/test', 'POST', [], [], [], [], '{"name":"Ada"}'), ApiV1MapperFixture::class);
expectRequestMapper($dto instanceof ApiV1MapperFixture && $dto->name === 'Ada', 'valid JSON must deserialize into the DTO.');

try {
    $mapper->map(Request::create('/api/v1/test', 'POST', [], [], [], [], '{"name":""}'), ApiV1MapperFixture::class);
    expectRequestMapper(false, 'constraint violations must reject the request.');
} catch (UnprocessableEntityHttpException) {
}

try {
    $mapper->map(Request::create('/api/v1/test', 'POST', [], [], [], [], '{bad json'), ApiV1MapperFixture::class);
    expectRequestMapper(false, 'malformed JSON must reject the request.');
} catch (BadRequestHttpException) {
}

echo "API v1 Serializer + Validator request mapping contract passed.\n";
