<?php
declare(strict_types=1);

use App\Application\System\Query\GetApiStatusQuery;
use App\Application\System\Query\GetApiStatusQueryHandler;
use App\Http\Api\V1\Controller\ApiStatusController;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Application\Query\QueryInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ApiV1QueryBusStub implements QueryBusInterface
{
    public ?QueryInterface $lastQuery = null;

    public function ask(QueryInterface $query): mixed
    {
        $this->lastQuery = $query;

        return (new GetApiStatusQueryHandler())($query);
    }
}

function expectApiV1(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "API v1 contract failed: {$message}\n");
        exit(1);
    }
}

$bus = new ApiV1QueryBusStub();
$response = (new ApiStatusController($bus))();
$payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

expectApiV1($response->getStatusCode() === 200, 'status controller must return 200.');
expectApiV1($bus->lastQuery instanceof GetApiStatusQuery, 'controller must delegate through QueryBus.');
expectApiV1($payload === [
    'ok' => true,
    'data' => [
        'status' => 'ok',
        'api_version' => 'v1',
        'runtime' => 'symfony',
    ],
], 'status response must preserve the versioned API envelope.');

echo "API v1 request -> QueryBus -> handler -> response contract passed.\n";
