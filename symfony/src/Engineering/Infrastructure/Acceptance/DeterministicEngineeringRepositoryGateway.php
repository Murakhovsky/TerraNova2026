<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Acceptance;

use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use RuntimeException;

final class DeterministicEngineeringRepositoryGateway implements EngineeringRepositoryGatewayInterface
{
    private const BASE_REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $stateFile;

    public function __construct()
    {
        $configured = trim((string) getenv('COS_ENGINEERING_FIXTURE_REPOSITORY_STATE'));
        $this->stateFile = $configured !== ''
            ? $configured
            : sys_get_temp_dir().'/cos-engineering-v01-fixture-repository.json';
        $this->ensureState();
    }

    public function available(): bool
    {
        return true;
    }

    public function currentBaseRevision(?string $branch = null): string
    {
        return self::BASE_REVISION;
    }

    public function configuredBaseBranch(): string
    {
        return 'main';
    }

    public function ensureBranch(string $branch, string $baseRevision): string
    {
        return $this->mutate(function (array &$state) use ($branch, $baseRevision): string {
            if (!isset($state['revisions'][$baseRevision])) {
                throw new RuntimeException('Fixture base revision not found: '.$baseRevision);
            }
            if (!isset($state['branches'][$branch])) $state['branches'][$branch] = $baseRevision;
            return (string) $state['branches'][$branch];
        });
    }

    public function filesAtRevision(array $paths, string $revision): array
    {
        $state = $this->read();
        $files = is_array($state['revisions'][$revision]['files'] ?? null)
            ? $state['revisions'][$revision]['files']
            : [];

        $result = [];
        foreach (array_values(array_unique(array_map('strval', $paths))) as $path) {
            if (!array_key_exists($path, $files)) continue;
            $body = (string) $files[$path];
            $result[] = [
                'path' => $path,
                'content' => $body,
                'complete' => true,
                'size' => strlen($body),
                'sha256' => hash('sha256', $body),
            ];
        }
        return $result;
    }

    public function existingPathsAtRevision(array $paths, string $revision): array
    {
        $state = $this->read();
        $files = is_array($state['revisions'][$revision]['files'] ?? null)
            ? $state['revisions'][$revision]['files']
            : [];

        $existing = [];
        foreach (array_values(array_unique(array_map('strval', $paths))) as $path) {
            if (array_key_exists($path, $files)) $existing[] = $path;
        }
        return $existing;
    }

    public function compareRevisions(string $baseRevision, string $headRevision): array
    {
        $state = $this->read();
        $base = is_array($state['revisions'][$baseRevision]['files'] ?? null) ? $state['revisions'][$baseRevision]['files'] : [];
        $head = is_array($state['revisions'][$headRevision]['files'] ?? null) ? $state['revisions'][$headRevision]['files'] : [];
        $paths = array_values(array_unique(array_merge(array_keys($base), array_keys($head))));
        sort($paths);

        $files = [];
        foreach ($paths as $path) {
            $before = $base[$path] ?? null;
            $after = $head[$path] ?? null;
            if ($before === $after) continue;
            $status = $before === null ? 'added' : ($after === null ? 'removed' : 'modified');
            $files[] = [
                'path' => $path,
                'status' => $status,
                'additions' => $after !== null ? max(1, substr_count((string) $after, "\n")) : 0,
                'deletions' => $before !== null ? max(1, substr_count((string) $before, "\n")) : 0,
                'patch' => 'fixture diff '.$baseRevision.'..'.$headRevision.' '.$path,
            ];
        }

        return [
            'base_revision' => $baseRevision,
            'head_revision' => $headRevision,
            'status' => $files === [] ? 'identical' : 'ahead',
            'ahead_by' => $files === [] ? 0 : 1,
            'behind_by' => 0,
            'files' => $files,
        ];
    }

    public function commitChanges(string $baseRevision, string $branch, array $changes, string $message): array
    {
        return $this->mutate(function (array &$state) use ($baseRevision, $branch, $changes, $message): array {
            if (!isset($state['revisions'][$baseRevision])) {
                throw new RuntimeException('Fixture commit base revision not found: '.$baseRevision);
            }
            if (isset($state['branches'][$branch]) && $state['branches'][$branch] !== $baseRevision) {
                throw new RuntimeException('Fixture branch head moved unexpectedly.');
            }

            $files = $state['revisions'][$baseRevision]['files'];
            $changed = [];
            foreach ($changes as $change) {
                if (!is_array($change)) throw new RuntimeException('Fixture repository change must be an object.');
                $path = trim((string) ($change['path'] ?? ''));
                $operation = strtoupper((string) ($change['operation'] ?? ''));
                if ($path === '') throw new RuntimeException('Fixture repository change path is required.');
                if ($operation === 'DELETE') {
                    unset($files[$path]);
                } elseif (in_array($operation, ['CREATE','UPDATE'], true)) {
                    $files[$path] = (string) ($change['content'] ?? '');
                } else {
                    throw new RuntimeException('Unsupported fixture repository operation: '.$operation);
                }
                $changed[$path] = true;
            }

            $ordinal = (int) ($state['commit_counter'] ?? 0) + 1;
            $state['commit_counter'] = $ordinal;
            $revision = sha1($branch.'|'.$ordinal.'|'.$message.'|'.json_encode($changes, JSON_THROW_ON_ERROR));
            $state['revisions'][$revision] = [
                'files' => $files,
                'changed_files' => array_keys($changed),
                'parent' => $baseRevision,
            ];
            $state['branches'][$branch] = $revision;

            foreach ($state['prs'] as &$pr) {
                if (($pr['branch'] ?? null) !== $branch || ($pr['state'] ?? null) !== 'open') continue;
                $pr['head_revision'] = $revision;
                $pr['files'] = array_values(array_unique(array_merge(
                    is_array($pr['files'] ?? null) ? $pr['files'] : [],
                    array_keys($changed),
                )));
            }
            unset($pr);

            return ['branch' => $branch, 'revision' => $revision, 'changed_files' => array_keys($changed)];
        });
    }

    public function openPullRequest(string $branch, string $title, string $body, ?string $baseBranch = null): array
    {
        return $this->mutate(function (array &$state) use ($branch, $title, $body): array {
            $head = (string) ($state['branches'][$branch] ?? '');
            if ($head === '') throw new RuntimeException('Fixture branch not found: '.$branch);

            foreach ($state['prs'] as $number => &$pr) {
                if (($pr['branch'] ?? null) !== $branch || ($pr['state'] ?? null) !== 'open') continue;
                $pr['head_revision'] = $head;
                $pr['title'] = $title;
                $pr['body'] = $body;
                unset($pr);
                return ['number' => (int) $number, 'url' => 'https://fixture.invalid/pr/'.$number, 'title' => $title];
            }
            unset($pr);

            $number = (int) ($state['next_pr'] ?? 9000);
            $state['next_pr'] = $number + 1;
            $state['prs'][(string) $number] = [
                'number' => $number,
                'branch' => $branch,
                'title' => $title,
                'body' => $body,
                'state' => 'open',
                'merged' => false,
                'merge_revision' => null,
                'head_revision' => $head,
                'base_revision' => self::BASE_REVISION,
                'files' => $state['revisions'][$head]['changed_files'] ?? [],
            ];
            return ['number' => $number, 'url' => 'https://fixture.invalid/pr/'.$number, 'title' => $title];
        });
    }

    public function pullRequestFiles(int $pullRequestNumber): array
    {
        $pr = $this->pr($pullRequestNumber);
        $files = [];
        foreach (is_array($pr['files'] ?? null) ? $pr['files'] : [] as $path) {
            $files[] = [
                'path' => (string) $path,
                'status' => 'modified',
                'additions' => 1,
                'deletions' => 0,
                'patch' => 'fixture patch '.(string) $path,
            ];
        }
        return $files;
    }

    public function commitChecks(string $revision): array
    {
        $checks = [
            ['name' => 'CI', 'status' => 'completed', 'conclusion' => 'success'],
            ['name' => 'Runtime', 'status' => 'completed', 'conclusion' => 'success'],
            ['name' => 'Static Analysis', 'status' => 'completed', 'conclusion' => 'success'],
        ];
        return [
            'state' => 'SUCCESS',
            'total' => count($checks),
            'passed' => count($checks),
            'failed' => 0,
            'pending' => 0,
            'checks' => $checks,
        ];
    }

    public function pullRequest(int $pullRequestNumber): array
    {
        $pr = $this->pr($pullRequestNumber);
        return [
            'number' => $pullRequestNumber,
            'url' => 'https://fixture.invalid/pr/'.$pullRequestNumber,
            'state' => (string) ($pr['state'] ?? 'open'),
            'merged' => (bool) ($pr['merged'] ?? false),
            'merge_revision' => isset($pr['merge_revision']) ? (string) $pr['merge_revision'] : null,
            'head_revision' => isset($pr['head_revision']) ? (string) $pr['head_revision'] : null,
            'base_revision' => isset($pr['base_revision']) ? (string) $pr['base_revision'] : null,
        ];
    }

    public function issue(int $issueNumber): array
    {
        return [
            'number' => $issueNumber,
            'title' => 'Fixture issue '.$issueNumber,
            'body' => 'Deterministic Engineering acceptance fixture.',
            'state' => 'open',
            'url' => 'https://fixture.invalid/issues/'.$issueNumber,
            'labels' => [],
            'is_pull_request' => false,
        ];
    }

    public function commentIssue(int $issueNumber, string $body): void
    {
        $this->mutate(function (array &$state) use ($issueNumber, $body): void {
            $state['issue_comments'][] = ['issue' => $issueNumber, 'body' => $body];
        });
    }

    public function addIssueLabels(int $issueNumber, array $labels): void
    {
        $this->mutate(function (array &$state) use ($issueNumber, $labels): void {
            $state['issue_labels'][(string) $issueNumber] = array_values(array_unique(array_map('strval', $labels)));
        });
    }

    public function markMerged(int $pullRequestNumber, string $actor = 'fixture-human'): string
    {
        return $this->mutate(function (array &$state) use ($pullRequestNumber, $actor): string {
            $key = (string) $pullRequestNumber;
            if (!isset($state['prs'][$key])) throw new RuntimeException('Fixture pull request not found.');
            $mergeRevision = sha1('human-merge|'.$actor.'|'.$pullRequestNumber.'|'.$state['prs'][$key]['head_revision']);
            $state['prs'][$key]['state'] = 'closed';
            $state['prs'][$key]['merged'] = true;
            $state['prs'][$key]['merge_revision'] = $mergeRevision;
            return $mergeRevision;
        });
    }

    public function reset(): void
    {
        @unlink($this->stateFile);
        $this->ensureState();
    }

    private function pr(int $number): array
    {
        $state = $this->read();
        $pr = $state['prs'][(string) $number] ?? null;
        if (!is_array($pr)) throw new RuntimeException('Fixture pull request not found: '.$number);
        return $pr;
    }

    private function ensureState(): void
    {
        if (is_file($this->stateFile)) return;
        $directory = dirname($this->stateFile);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create fixture repository state directory.');
        }
        $this->write([
            'commit_counter' => 0,
            'next_pr' => 9000,
            'branches' => [],
            'revisions' => [
                self::BASE_REVISION => ['files' => [], 'changed_files' => [], 'parent' => null],
            ],
            'prs' => [],
            'issue_comments' => [],
            'issue_labels' => [],
        ]);
    }

    private function read(): array
    {
        $this->ensureState();
        $decoded = json_decode((string) file_get_contents($this->stateFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Fixture repository state is invalid.');
        return $decoded;
    }

    private function write(array $state): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }

    private function mutate(callable $callback): mixed
    {
        $this->ensureState();
        $handle = fopen($this->stateFile, 'c+');
        if ($handle === false) throw new RuntimeException('Cannot open fixture repository state.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock fixture repository state.');
            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = trim((string) $raw) !== ''
                ? json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR)
                : [];
            if (!is_array($state)) $state = [];
            $result = $callback($state);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($handle);
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }
}
