<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Repository;

use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use RuntimeException;

final readonly class GitHubEngineeringRepositoryGateway implements EngineeringRepositoryGatewayInterface
{
    public function __construct(
        private string $repositoryFullName,
        private string $token,
        private string $baseBranch = 'main',
        private string $apiBase = 'https://api.github.com',
    ) {}

    public function available(): bool
    {
        return trim($this->repositoryFullName) !== '' && trim($this->token) !== '';
    }

    public function currentBaseRevision(): string
    {
        $this->assertAvailable();
        $ref = $this->request('GET', '/git/ref/heads/'.rawurlencode($this->baseBranch), null, [200]);
        $sha = (string) ($ref['object']['sha'] ?? '');
        if ($sha === '') throw new RuntimeException('GitHub base branch does not expose a revision.');
        return $sha;
    }

    public function filesAtRevision(array $paths, string $revision): array
    {
        $this->assertAvailable();
        $revision = trim($revision);
        if ($revision === '') throw new RuntimeException('Repository revision is required.');

        $result = [];
        $totalBytes = 0;
        foreach (array_slice(array_values(array_unique($paths)), 0, 20) as $rawPath) {
            if (!is_string($rawPath)) continue;
            $path = $this->assertPath($rawPath);
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
            $response = $this->requestNullable(
                'GET',
                '/contents/'.$encodedPath.'?ref='.rawurlencode($revision),
                [200, 404],
            );
            if ($response === null || ($response['type'] ?? null) !== 'file') continue;
            if (($response['encoding'] ?? null) !== 'base64' || !is_string($response['content'] ?? null)) continue;

            $decoded = base64_decode(str_replace(["\r","\n"], '', $response['content']), true);
            if (!is_string($decoded)) continue;

            $size = strlen($decoded);
            $remaining = min(65536, 524288 - $totalBytes);
            if ($remaining <= 0) break;
            $content = substr($decoded, 0, $remaining);
            $totalBytes += strlen($content);

            $result[] = [
                'path' => $path,
                'content' => $content,
                'complete' => strlen($content) >= $size,
                'size' => $size,
                'sha256' => hash('sha256', $content),
            ];
        }

        return $result;
    }

    public function compareRevisions(string $baseRevision, string $headRevision): array
    {
        $this->assertAvailable();
        $baseRevision = trim($baseRevision);
        $headRevision = trim($headRevision);
        if ($baseRevision === '' || $headRevision === '') {
            throw new RuntimeException('Both repository revisions are required for comparison.');
        }

        if ($baseRevision === $headRevision) {
            return [
                'base_revision' => $baseRevision,
                'head_revision' => $headRevision,
                'status' => 'identical',
                'ahead_by' => 0,
                'behind_by' => 0,
                'files' => [],
            ];
        }

        $comparison = $this->request(
            'GET',
            '/compare/'.rawurlencode($baseRevision).'...'.rawurlencode($headRevision),
            null,
            [200],
        );

        $files = [];
        foreach (array_slice(is_array($comparison['files'] ?? null) ? $comparison['files'] : [], 0, 100) as $file) {
            if (!is_array($file)) continue;
            $files[] = [
                'path' => (string) ($file['filename'] ?? ''),
                'status' => (string) ($file['status'] ?? ''),
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
                'patch' => isset($file['patch']) ? mb_substr((string) $file['patch'], 0, 20000) : null,
            ];
        }

        return [
            'base_revision' => $baseRevision,
            'head_revision' => $headRevision,
            'status' => (string) ($comparison['status'] ?? 'unknown'),
            'ahead_by' => (int) ($comparison['ahead_by'] ?? 0),
            'behind_by' => (int) ($comparison['behind_by'] ?? 0),
            'files' => $files,
        ];
    }

    public function commitChanges(string $baseRevision, string $branch, array $changes, string $message): array
    {
        $this->assertAvailable();
        $branch = $this->assertBranch($branch);
        $this->validateChanges($changes);

        $head = $this->ensureBranch($branch, $baseRevision);
        $commit = $this->request('GET', '/git/commits/'.rawurlencode($head), null, [200]);
        $baseTree = (string) ($commit['tree']['sha'] ?? '');
        if ($baseTree === '') throw new RuntimeException('GitHub base commit does not expose a tree.');

        $treeEntries = [];
        $changedFiles = [];
        foreach ($changes as $change) {
            $path = $this->assertPath((string) $change['path']);
            $operation = (string) $change['operation'];
            $changedFiles[] = $path;

            if ($operation === 'DELETE') {
                $treeEntries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                continue;
            }

            $blob = $this->request('POST', '/git/blobs', [
                'content' => base64_encode((string) ($change['content'] ?? '')),
                'encoding' => 'base64',
            ], [201]);
            $sha = (string) ($blob['sha'] ?? '');
            if ($sha === '') throw new RuntimeException('GitHub blob creation did not return sha.');
            $treeEntries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $sha];
        }

        $tree = $this->request('POST', '/git/trees', [
            'base_tree' => $baseTree,
            'tree' => $treeEntries,
        ], [201]);
        $treeSha = (string) ($tree['sha'] ?? '');
        if ($treeSha === '') throw new RuntimeException('GitHub tree creation did not return sha.');
        if ($treeSha === $baseTree) {
            return [
                'branch' => $branch,
                'revision' => $head,
                'changed_files' => array_values(array_unique($changedFiles)),
            ];
        }

        $newCommit = $this->request('POST', '/git/commits', [
            'message' => trim($message) !== '' ? $message : 'feat(engineering): autonomous implementation',
            'tree' => $treeSha,
            'parents' => [$head],
        ], [201]);
        $revision = (string) ($newCommit['sha'] ?? '');
        if ($revision === '') throw new RuntimeException('GitHub commit creation did not return sha.');

        $this->request('PATCH', '/git/refs/heads/'.rawurlencode($branch), [
            'sha' => $revision,
            'force' => false,
        ], [200]);

        return [
            'branch' => $branch,
            'revision' => $revision,
            'changed_files' => array_values(array_unique($changedFiles)),
        ];
    }

    public function openPullRequest(string $branch, string $title, string $body): array
    {
        $this->assertAvailable();
        $response = $this->request('POST', '/pulls', [
            'title' => trim($title) !== '' ? $title : 'Engineering change',
            'head' => $branch,
            'base' => $this->baseBranch,
            'body' => $body,
            'draft' => false,
        ], [201, 422]);

        if (isset($response['number'])) {
            return [
                'number' => (int) $response['number'],
                'url' => (string) ($response['html_url'] ?? ''),
                'title' => (string) ($response['title'] ?? $title),
            ];
        }

        // 422 is also returned when an open PR already exists for the branch.
        $existing = $this->request('GET', '/pulls?state=open&head='.rawurlencode($this->owner().':'.$branch), null, [200]);
        if (!isset($existing[0]['number'])) {
            throw new RuntimeException('GitHub could not create or resolve a pull request for branch '.$branch.'.');
        }

        return [
            'number' => (int) $existing[0]['number'],
            'url' => (string) ($existing[0]['html_url'] ?? ''),
            'title' => (string) ($existing[0]['title'] ?? $title),
        ];
    }

    public function pullRequestFiles(int $pullRequestNumber): array
    {
        $this->assertAvailable();
        if ($pullRequestNumber <= 0) throw new RuntimeException('Pull request number must be positive.');
        $files = $this->request('GET', '/pulls/'.$pullRequestNumber.'/files?per_page=100', null, [200]);

        $result = [];
        foreach ($files as $file) {
            if (!is_array($file)) continue;
            $result[] = [
                'path' => (string) ($file['filename'] ?? ''),
                'status' => (string) ($file['status'] ?? ''),
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
                'patch' => isset($file['patch']) ? mb_substr((string) $file['patch'], 0, 20000) : null,
            ];
        }

        if (count($result) > 100) $result = array_slice($result, 0, 100);
        return $result;
    }

    public function issue(int $issueNumber): array
    {
        $this->assertAvailable();
        if ($issueNumber <= 0) throw new RuntimeException('Issue number must be positive.');
        $issue = $this->request('GET', '/issues/'.$issueNumber, null, [200]);

        $labels = [];
        foreach (is_array($issue['labels'] ?? null) ? $issue['labels'] : [] as $label) {
            if (is_array($label) && isset($label['name'])) $labels[] = (string) $label['name'];
        }

        return [
            'number' => (int) ($issue['number'] ?? $issueNumber),
            'title' => (string) ($issue['title'] ?? ''),
            'body' => (string) ($issue['body'] ?? ''),
            'state' => (string) ($issue['state'] ?? ''),
            'url' => (string) ($issue['html_url'] ?? ''),
            'labels' => $labels,
            'is_pull_request' => isset($issue['pull_request']),
        ];
    }

    public function commentIssue(int $issueNumber, string $body): void
    {
        $this->assertAvailable();
        if ($issueNumber <= 0 || trim($body) === '') throw new RuntimeException('Issue comment requires issue number and body.');
        $this->request('POST', '/issues/'.$issueNumber.'/comments', ['body' => $body], [201]);
    }

    public function addIssueLabels(int $issueNumber, array $labels): void
    {
        $this->assertAvailable();
        $labels = array_values(array_unique(array_filter(array_map(
            static fn (mixed $label): string => trim((string) $label),
            $labels,
        ), static fn (string $label): bool => $label !== '')));
        if ($issueNumber <= 0 || $labels === []) return;

        try {
            foreach ($labels as $label) {
                $existing = $this->requestNullable('GET', '/labels/'.rawurlencode($label), [200, 404]);
                if ($existing !== null) continue;
                $this->request('POST', '/labels', [
                    'name' => $label,
                    'color' => '1d76db',
                    'description' => 'COS Engineering workflow label.',
                ], [201, 422]);
            }
            $this->request('POST', '/issues/'.$issueNumber.'/labels', ['labels' => $labels], [200]);
        } catch (RuntimeException) {
            // Labels improve operations but must not block feature intake.
        }
    }

    public function pullRequest(int $pullRequestNumber): array
    {
        $this->assertAvailable();
        if ($pullRequestNumber <= 0) throw new RuntimeException('Pull request number must be positive.');
        $pr = $this->request('GET', '/pulls/'.$pullRequestNumber, null, [200]);

        return [
            'number' => (int) ($pr['number'] ?? $pullRequestNumber),
            'url' => (string) ($pr['html_url'] ?? ''),
            'state' => (string) ($pr['state'] ?? ''),
            'merged' => (bool) ($pr['merged'] ?? false),
            'merge_revision' => isset($pr['merge_commit_sha']) && is_string($pr['merge_commit_sha'])
                ? $pr['merge_commit_sha']
                : null,
        ];
    }

    public function commitChecks(string $revision): array
    {
        $this->assertAvailable();
        $revision = trim($revision);
        if ($revision === '') throw new RuntimeException('Commit revision is required for CI checks.');

        $checksResponse = $this->request('GET', '/commits/'.rawurlencode($revision).'/check-runs?per_page=100', null, [200]);
        $runs = is_array($checksResponse['check_runs'] ?? null) ? $checksResponse['check_runs'] : [];
        $checks = [];
        $passed = 0;
        $failed = 0;
        $pending = 0;

        foreach ($runs as $run) {
            if (!is_array($run)) continue;
            $status = (string) ($run['status'] ?? '');
            $conclusion = isset($run['conclusion']) ? (string) $run['conclusion'] : null;
            if ($status !== 'completed') {
                ++$pending;
            } elseif (in_array($conclusion, ['success','neutral','skipped'], true)) {
                ++$passed;
            } else {
                ++$failed;
            }
            $checks[] = [
                'name' => (string) ($run['name'] ?? ''),
                'status' => $status,
                'conclusion' => $conclusion,
                'url' => (string) ($run['html_url'] ?? ''),
            ];
        }

        $combined = $this->request('GET', '/commits/'.rawurlencode($revision).'/status', null, [200]);
        foreach (is_array($combined['statuses'] ?? null) ? $combined['statuses'] : [] as $status) {
            if (!is_array($status)) continue;
            $state = (string) ($status['state'] ?? 'pending');
            if ($state === 'success') ++$passed;
            elseif (in_array($state, ['failure','error'], true)) ++$failed;
            else ++$pending;
            $checks[] = [
                'name' => (string) ($status['context'] ?? 'commit-status'),
                'status' => $state === 'pending' ? 'in_progress' : 'completed',
                'conclusion' => $state,
                'url' => (string) ($status['target_url'] ?? ''),
            ];
        }

        $total = count($checks);
        $state = $failed > 0 ? 'FAILED' : (($pending > 0 || $total === 0) ? 'PENDING' : 'SUCCESS');

        return compact('state','total','passed','failed','pending','checks');
    }

    private function ensureBranch(string $branch, string $baseRevision): string
    {
        $existing = $this->requestNullable('GET', '/git/ref/heads/'.rawurlencode($branch), [200, 404]);
        if (is_array($existing) && isset($existing['object']['sha'])) {
            $head = (string) $existing['object']['sha'];
            if ($head !== $baseRevision) {
                throw new RuntimeException(sprintf(
                    'Engineering branch %s advanced from expected revision %s to %s; rerun Developer with fresh repository context.',
                    $branch,
                    $baseRevision,
                    $head,
                ));
            }
            return $head;
        }

        $created = $this->request('POST', '/git/refs', [
            'ref' => 'refs/heads/'.$branch,
            'sha' => $baseRevision,
        ], [201]);

        return (string) ($created['object']['sha'] ?? $baseRevision);
    }

    private function validateChanges(array $changes): void
    {
        if ($changes === [] || count($changes) > 20) {
            throw new RuntimeException('Engineering repository changes must contain between 1 and 20 files.');
        }
        foreach ($changes as $change) {
            if (!is_array($change)) throw new RuntimeException('Engineering repository change must be an object.');
            $operation = (string) ($change['operation'] ?? '');
            $this->assertPath((string) ($change['path'] ?? ''));
            if (!in_array($operation, ['CREATE','UPDATE','DELETE'], true)) {
                throw new RuntimeException('Unsupported repository change operation.');
            }
            if ($operation !== 'DELETE' && strlen((string) ($change['content'] ?? '')) > 250000) {
                throw new RuntimeException('Engineering repository file content exceeds V0.1 limit.');
            }
        }
    }

    private function assertPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException('Unsafe engineering repository path.');
        }
        foreach (['.git/','.env','vendor/','node_modules/','var/'] as $forbidden) {
            if ($path === rtrim($forbidden, '/') || str_starts_with($path, $forbidden)) {
                throw new RuntimeException('Engineering repository path is forbidden: '.$path);
            }
        }
        return $path;
    }

    private function assertBranch(string $branch): string
    {
        $branch = trim($branch);
        if ($branch === '' || !preg_match('/^[A-Za-z0-9._\/-]{1,180}$/', $branch) || str_contains($branch, '..')) {
            throw new RuntimeException('Unsafe engineering branch name.');
        }
        return $branch;
    }

    private function assertAvailable(): void
    {
        if (!$this->available()) {
            throw new RuntimeException('Engineering GitHub repository gateway is not configured.');
        }
    }

    private function owner(): string
    {
        $parts = explode('/', $this->repositoryFullName, 2);
        return $parts[0] ?? '';
    }

    private function requestNullable(string $method, string $path, array $expected): ?array
    {
        $result = $this->requestWithStatus($method, $path, null);
        if ($result['status'] === 404) return null;
        if (!in_array($result['status'], $expected, true)) $this->throwHttp($result['status'], $result['body']);
        return $result['body'];
    }

    private function request(string $method, string $path, ?array $payload, array $expected): array
    {
        $result = $this->requestWithStatus($method, $path, $payload);
        if (!in_array($result['status'], $expected, true)) $this->throwHttp($result['status'], $result['body']);
        return $result['body'];
    }

    /** @return array{status:int,body:array} */
    private function requestWithStatus(string $method, string $path, ?array $payload): array
    {
        $url = rtrim($this->apiBase, '/').'/repos/'.$this->repositoryFullName.$path;
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Could not initialize GitHub HTTP client.');

        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer '.$this->token,
            'User-Agent: COS-Engineering-Manager/0.1',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('GitHub HTTP request failed: '.$error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $body = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) $body = ['message' => 'Invalid GitHub JSON response.'];

        return ['status' => $status, 'body' => $body];
    }

    private function throwHttp(int $status, array $body): never
    {
        $message = trim((string) ($body['message'] ?? 'GitHub request failed.'));
        throw new RuntimeException(sprintf('GitHub API error %d: %s', $status, mb_substr($message, 0, 500)));
    }
}
