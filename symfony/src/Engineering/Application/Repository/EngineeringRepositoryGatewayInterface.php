<?php
declare(strict_types=1);

namespace App\Engineering\Application\Repository;

interface EngineeringRepositoryGatewayInterface
{
    public function available(): bool;
    public function currentBaseRevision(): string;

    /** @param list<array{path:string,operation:string,content?:string|null}> $changes
     *  @return array{branch:string,revision:string,changed_files:list<string>}
     */
    public function commitChanges(
        string $baseRevision,
        string $branch,
        array $changes,
        string $message,
    ): array;

    /** @return array{number:int,url:string,title:string} */
    public function openPullRequest(string $branch, string $title, string $body): array;

    /** @return list<array{path:string,status:string,additions:int,deletions:int,patch:?string}> */
    public function pullRequestFiles(int $pullRequestNumber): array;
}
