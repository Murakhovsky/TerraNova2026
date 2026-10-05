<?php
declare(strict_types=1);

namespace App\Engineering\Application\Repository;

interface EngineeringRepositoryGatewayInterface
{
    public function available(): bool;
    public function configuredRepository(): string;
    public function currentBaseRevision(?string $branch = null): string;
    public function configuredBaseBranch(): string;
    public function ensureBranch(string $branch, string $baseRevision): string;

    /**
     * Read exact repository files at a specific revision.
     *
     * @param list<string> $paths
     * @return list<array{path:string,content:string,complete:bool,size:int,sha256:string}>
     */
    public function filesAtRevision(array $paths, string $revision): array;

    /**
     * @return array{base_revision:string,head_revision:string,status:string,ahead_by:int,behind_by:int,files:list<array{path:string,status:string,additions:int,deletions:int,patch:?string}>}
     */
    public function compareRevisions(string $baseRevision, string $headRevision): array;

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
    public function openPullRequest(string $branch, string $title, string $body, ?string $baseBranch = null): array;

    /** @return list<array{path:string,status:string,additions:int,deletions:int,patch:?string}> */
    public function pullRequestFiles(int $pullRequestNumber): array;

    /** @return array{state:string,total:int,passed:int,failed:int,pending:int,checks:list<array<string,mixed>>} */
    public function commitChecks(string $revision): array;

    /** @return array{number:int,url:string,state:string,merged:bool,merge_revision:?string,head_revision:?string,base_revision:?string} */
    public function pullRequest(int $pullRequestNumber): array;

    /** @return array{number:int,title:string,body:string,state:string,url:string,labels:list<string>,is_pull_request:bool} */
    public function issue(int $issueNumber): array;
    public function commentIssue(int $issueNumber, string $body): void;
    /** @param list<string> $labels */
    public function addIssueLabels(int $issueNumber, array $labels): void;
}
