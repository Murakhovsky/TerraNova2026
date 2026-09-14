<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Network\Reso;

interface ResoWebApiTransportInterface
{
    /**
     * Return a normalized RESO page:
     * ['records' => list<array>, 'next_cursor' => ?string, 'has_more' => bool, 'metadata' => array].
     * Authentication and endpoint resolution belong to the transport/configuration owner.
     */
    public function pull(string $configurationReference, ?string $cursor, int $limit): array;

    /**
     * Accept normalized outbound records and return:
     * ['succeeded' => list<string>, 'failed' => array<string,string>, 'next_cursor' => ?string].
     */
    public function push(string $configurationReference, array $records, ?string $cursor): array;
}
