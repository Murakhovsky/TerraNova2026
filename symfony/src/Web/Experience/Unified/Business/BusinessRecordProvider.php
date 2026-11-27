<?php
declare(strict_types=1);

namespace App\Web\Experience\Unified\Business;

use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;

/**
 * Optional UX-03 feature projection on an already registered Workspace provider.
 * No new registry, authorization, or command interface. The existing provider
 * set selects the owner; native Domain Query contracts own all business data.
 */
interface BusinessRecordProvider extends WorkspaceProviderInterface
{
    public function supportsRecords(string $workspaceId): bool;

    /** @return array{items:list<SearchResult>,has_more:bool,notice:?string} */
    public function records(WebExtensionContext $context, string $workspaceId, string $query, int $page): array;

    /**
     * Exact, independently authorized native readback, never browser data.
     * @return array{hit:SearchResult,facts:list<array{label:string,value:string}>,tables:list<array{title:string,columns:list<string>,rows:list<list<string>>,notice:?string}>}|null
     */
    public function record(WebExtensionContext $context, string $workspaceId, EntityRef $entity): ?array;
}
