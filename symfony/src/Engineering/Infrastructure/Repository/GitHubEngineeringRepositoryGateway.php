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

    private function ensureBranch(string $branch, string $baseRevision): string
    {
        $existing = $this->requestNullable('GET', '/git/ref/heads/'.rawurlencode($branch), [200, 404]);
        if (is_array($existing) && isset($existing['object']['sha'])) {
            return (string) $existing['object']['sha'];
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
