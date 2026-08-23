<?php
declare(strict_types=1);

namespace Modules\Spatial\Services;

use Common\Services\DatabaseService;
use PDO;
use RuntimeException;
use Throwable;

class SpatialProcessingService
{
    public function __construct(
        private DatabaseService $database,
        private string $blenderBinary = '',
        private string $gltfTransformBinary = ''
    ) {
    }

    public function process(int $limit = 10): array
    {
        $jobs = $this->claim(max(1, min(50, $limit)));
        $stats = ['claimed' => count($jobs), 'completed' => 0, 'failed' => 0];
        foreach ($jobs as $job) {
            try {
                $output = $this->runJob($job);
                $this->complete($job, $output);
                $stats['completed']++;
            } catch (Throwable $e) {
                $this->retry($job, $e->getMessage());
                $stats['failed']++;
            }
        }
        return $stats;
    }

    public function job(string $publicId): ?array
    {
        return $this->database->fetchOne('
            SELECT public_id, scene_id, asset_id, job_type, status, attempts, progress,
                   error_message, started_at, completed_at, created_at, updated_at
            FROM tn_spatial_processing_jobs WHERE public_id = :public_id LIMIT 1
        ', ['public_id' => $publicId]);
    }

    private function claim(int $limit): array
    {
        $token = bin2hex(random_bytes(16));
        $pdo = $this->database->connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('
                UPDATE tn_spatial_processing_jobs
                SET status = "processing", attempts = attempts + 1, progress = 5,
                    locked_at = NOW(), started_at = COALESCE(started_at, NOW()), lock_token = :token
                WHERE attempts < 3 AND available_at <= NOW()
                  AND (status IN ("pending", "failed") OR (status = "processing" AND locked_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)))
                ORDER BY id LIMIT ' . $limit . '
            ');
            $statement->execute(['token' => $token]);
            $fetch = $pdo->prepare('
                SELECT j.*, a.format, a.asset_type, a.storage, a.uri, a.media_id,
                       m.storage_path, m.original_name
                FROM tn_spatial_processing_jobs j
                LEFT JOIN tn_spatial_assets a ON a.id = j.asset_id
                LEFT JOIN tn_media_assets m ON m.id = a.media_id
                WHERE j.lock_token = :token ORDER BY j.id
            ');
            $fetch->execute(['token' => $token]);
            $jobs = $fetch->fetchAll(PDO::FETCH_ASSOC);
            $pdo->commit();
            return $jobs;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function runJob(array $job): array
    {
        if (empty($job['asset_id']) || ($job['storage'] ?? '') !== 'local') {
            throw new RuntimeException('Processing requires a local Spatial asset.');
        }
        $path = $this->localPath((string) ($job['storage_path'] ?? ''));
        return match ((string) $job['job_type']) {
            'validate' => $this->validate($job, $path),
            'inspect' => $this->inspect($job, $path),
            'convert' => $this->convert($job, $path),
            'optimize' => $this->optimize($job, $path),
            default => throw new RuntimeException('Spatial job type is not implemented by this worker.'),
        };
    }

    private function validate(array $job, string $path): array
    {
        $format = mb_strtolower((string) $job['format']);
        $head = (string) file_get_contents($path, false, null, 0, 16);
        if ($format === 'glb') {
            if (substr($head, 0, 4) !== 'glTF') {
                throw new RuntimeException('Invalid GLB magic header.');
            }
            $version = unpack('V', substr($head, 4, 4))[1] ?? 0;
            if ((int) $version !== 2) {
                throw new RuntimeException('Only glTF 2.0 GLB assets are supported.');
            }
        } elseif ($format === 'gltf') {
            $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!str_starts_with((string) ($json['asset']['version'] ?? ''), '2')) {
                throw new RuntimeException('Only glTF 2.x assets are supported.');
            }
        } elseif ($format === 'usdz' && substr($head, 0, 2) !== 'PK') {
            throw new RuntimeException('Invalid USDZ archive.');
        }
        return ['validated' => true, 'format' => $format, 'size_bytes' => filesize($path) ?: 0];
    }

    private function inspect(array $job, string $path): array
    {
        return [
            'inspected' => true,
            'format' => (string) $job['format'],
            'size_bytes' => filesize($path) ?: 0,
            'checksum' => hash_file('sha256', $path) ?: null,
        ];
    }

    private function convert(array $job, string $path): array
    {
        if ($this->blenderBinary === '' || !is_file($this->blenderBinary)) {
            throw new RuntimeException('Blender converter is not configured on this server.');
        }
        $output = preg_replace('/\.[^.]+$/', '-web.glb', $path) ?: ($path . '-web.glb');
        $this->command([
            $this->blenderBinary,
            '--background',
            '--python', BASE_PATH . '/bin/spatial-convert.py',
            '--', $path, $output,
        ]);
        if (!is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('Blender did not create a GLB output.');
        }
        $derivedId = $this->derivedAsset($job, $output, 'model_web', 'glb');
        return ['converted' => true, 'derived_asset_id' => $derivedId];
    }

    private function optimize(array $job, string $path): array
    {
        $binary = $this->gltfTransformBinary;
        if ($binary === '') {
            $candidate = BASE_PATH . '/node_modules/.bin/gltf-transform' . (PHP_OS_FAMILY === 'Windows' ? '.cmd' : '');
            $binary = is_file($candidate) ? $candidate : '';
        }
        if ($binary === '' || !is_file($binary)) {
            throw new RuntimeException('glTF Transform is not configured on this server.');
        }
        $output = preg_replace('/\.glb$/i', '-optimized.glb', $path) ?: ($path . '-optimized.glb');
        $this->command([$binary, 'optimize', $path, $output, '--compress', 'meshopt']);
        if (!is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('glTF optimization did not create an output.');
        }
        $derivedId = $this->derivedAsset($job, $output, 'model_web', 'glb');
        return ['optimized' => true, 'derived_asset_id' => $derivedId];
    }

    private function derivedAsset(array $job, string $path, string $assetType, string $format): int
    {
        $relative = str_replace('\\', '/', substr($path, strlen(str_replace('/', DIRECTORY_SEPARATOR, BASE_PATH . '/public/'))));
        $publicId = 'SPA-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(5)));
        $pdo = $this->database->connection();
        $pdo->prepare('
            INSERT INTO tn_media_assets (
                public_id, kind, storage, original_name, mime_type, extension, size_bytes,
                storage_path, public_url, checksum, status
            ) VALUES (:public_id, "model", "local", :name, "model/gltf-binary", :extension,
                :size, :path, :url, :checksum, "uploaded")
        ')->execute([
            'public_id' => 'MDA-' . substr($publicId, 4),
            'name' => basename($path),
            'extension' => $format,
            'size' => filesize($path) ?: 0,
            'path' => $relative,
            'url' => '/' . $relative,
            'checksum' => hash_file('sha256', $path) ?: null,
        ]);
        $mediaId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE tn_spatial_assets SET is_primary = 0 WHERE scene_id = :scene_id AND asset_type = :asset_type')
            ->execute(['scene_id' => $job['scene_id'], 'asset_type' => $assetType]);
        $pdo->prepare('
            INSERT INTO tn_spatial_assets (
                public_id, scene_id, version_id, media_id, asset_type, format, storage, uri,
                status, is_primary, size_bytes, checksum, metadata_json
            ) SELECT :public_id, scene_id, version_id, :media_id, :asset_type, :format, "local", :uri,
                "ready", 1, :size, :checksum, :metadata FROM tn_spatial_assets WHERE id = :source_id
        ')->execute([
            'public_id' => $publicId,
            'media_id' => $mediaId,
            'asset_type' => $assetType,
            'format' => $format,
            'uri' => '/' . $relative,
            'size' => filesize($path) ?: 0,
            'checksum' => hash_file('sha256', $path) ?: null,
            'metadata' => json_encode(['derived_from' => (int) $job['asset_id']], JSON_UNESCAPED_SLASHES),
            'source_id' => $job['asset_id'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function command(array $parts): void
    {
        $command = implode(' ', array_map('escapeshellarg', $parts));
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, BASE_PATH);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Spatial processor.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException(mb_substr(trim($stderr ?: $stdout ?: 'Spatial processor failed.'), 0, 2000));
        }
    }

    private function localPath(string $storagePath): string
    {
        $publicRoot = realpath(BASE_PATH . '/public');
        $candidate = realpath(BASE_PATH . '/public/' . ltrim(str_replace('\\', '/', $storagePath), '/'));
        if (!$publicRoot || !$candidate || !str_starts_with(str_replace('\\', '/', $candidate), str_replace('\\', '/', $publicRoot) . '/')) {
            throw new RuntimeException('Spatial asset path is outside public storage.');
        }
        return $candidate;
    }

    private function complete(array $job, array $output): void
    {
        $pdo = $this->database->connection();
        $pdo->prepare('
            UPDATE tn_spatial_processing_jobs
            SET status = "completed", progress = 100, output_json = :output, error_message = NULL,
                completed_at = NOW(), locked_at = NULL, lock_token = NULL
            WHERE id = :id AND lock_token = :token LIMIT 1
        ')->execute([
            'id' => $job['id'],
            'token' => $job['lock_token'],
            'output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (!empty($job['asset_id'])) {
            $pdo->prepare('UPDATE tn_spatial_assets SET status = "ready", error_message = NULL WHERE id = :id')
                ->execute(['id' => $job['asset_id']]);
        }
        $pdo->prepare('UPDATE tn_spatial_scenes SET status = "review" WHERE id = :id AND status = "processing"')
            ->execute(['id' => $job['scene_id']]);
    }

    private function retry(array $job, string $error): void
    {
        $final = (int) $job['attempts'] >= 3;
        $delay = min(30, 2 ** max(0, (int) $job['attempts'] - 1));
        $pdo = $this->database->connection();
        $pdo->prepare('
            UPDATE tn_spatial_processing_jobs
            SET status = "failed", progress = 0, error_message = :error,
                available_at = DATE_ADD(NOW(), INTERVAL ' . $delay . ' MINUTE), locked_at = NULL, lock_token = NULL
            WHERE id = :id AND lock_token = :token LIMIT 1
        ')->execute([
            'id' => $job['id'],
            'token' => $job['lock_token'],
            'error' => mb_substr($error, 0, 2000),
        ]);
        if ($final && !empty($job['asset_id'])) {
            $pdo->prepare('UPDATE tn_spatial_assets SET status = "failed", error_message = :error WHERE id = :id')
                ->execute(['id' => $job['asset_id'], 'error' => mb_substr($error, 0, 2000)]);
            $pdo->prepare('UPDATE tn_spatial_scenes SET status = "failed" WHERE id = :id AND status = "processing"')
                ->execute(['id' => $job['scene_id']]);
        }
    }
}
