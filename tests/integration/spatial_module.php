<?php
declare(strict_types=1);

use Infrastructure\Database\Connection\DatabaseService;
use Infrastructure\Media\SpatialAssetService;
use Infrastructure\Spatial\SpatialProcessingService;
use Infrastructure\Persistence\MySql\Spatial\SpatialSceneService;

define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
require APP_PATH . '/config/loader.php';

$config = require APP_PATH . '/config/config.php';
$database = new DatabaseService($config->database);
$pdo = $database->connection();
$cleanupArgument = array_values(array_filter($argv ?? [], static fn(string $argument): bool => str_starts_with($argument, '--cleanup=')));
if ($cleanupArgument) {
    $cleanupSceneId = (int) substr($cleanupArgument[0], strlen('--cleanup='));
    $cleanupAssets = $database->fetchAll('
        SELECT a.media_id, m.storage_path FROM tn_spatial_assets a
        LEFT JOIN tn_media_assets m ON m.id = a.media_id WHERE a.scene_id = :scene_id
    ', ['scene_id' => $cleanupSceneId]);
    $pdo->prepare('DELETE FROM tn_spatial_scenes WHERE id = :id')->execute(['id' => $cleanupSceneId]);
    foreach ($cleanupAssets as $cleanupAsset) {
        if (!empty($cleanupAsset['media_id'])) {
            $pdo->prepare('DELETE FROM tn_media_assets WHERE id = :id')->execute(['id' => $cleanupAsset['media_id']]);
        }
        $cleanupPath = BASE_PATH . '/public/' . ltrim((string) ($cleanupAsset['storage_path'] ?? ''), '/');
        if (is_file($cleanupPath)) {
            unlink($cleanupPath);
        }
    }
    echo 'spatial fixture ' . $cleanupSceneId . " removed\n";
    exit(0);
}
$assets = new SpatialAssetService($database, 5 * 1024 * 1024);
$scenes = new SpatialSceneService($database, $assets);
$processor = new SpatialProcessingService($database);
$sceneId = 0;
$userId = 0;
$mediaIds = [];
$storedPaths = [];
$fixture = BASE_PATH . '/tmp/spatial-test-' . bin2hex(random_bytes(5)) . '.glb';
$keepFixture = in_array('--keep', $argv ?? [], true);
$passed = false;

try {
    $email = 'spatial-test-' . bin2hex(random_bytes(5)) . '@example.test';
    $statement = $pdo->prepare('INSERT INTO tn_users (email, password_hash, full_name, role, status) VALUES (:email, :hash, "Spatial Test", "admin", "active")');
    $statement->execute(['email' => $email, 'hash' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $user = ['id' => $userId, 'role' => 'admin'];

    $created = $scenes->save([
        'title' => 'Spatial integration ' . bin2hex(random_bytes(3)),
        'scene_type' => 'model',
        'viewer_type' => 'threejs',
        'provider' => 'native',
        'capture_method' => 'lidar',
        'device_name' => 'Integration fixture',
        'default_camera_json' => '{"position":{"x":3,"y":2,"z":4}}',
        'settings_json' => '{"background":"#e9ece9"}',
    ], $user);
    if (empty($created['ok']) || empty($created['id'])) {
        throw new RuntimeException('Scene creation failed: ' . json_encode($created, JSON_UNESCAPED_UNICODE));
    }
    $sceneId = (int) $created['id'];
    $scene = $scenes->sceneReference($sceneId);

    $positions = [
        -1, -1, -1, 1, -1, -1, 1, 1, -1, -1, 1, -1,
        -1, -1, 1, 1, -1, 1, 1, 1, 1, -1, 1, 1,
    ];
    $indices = [
        0, 1, 2, 0, 2, 3, 4, 6, 5, 4, 7, 6,
        0, 4, 5, 0, 5, 1, 3, 2, 6, 3, 6, 7,
        1, 5, 6, 1, 6, 2, 0, 3, 7, 0, 7, 4,
    ];
    $binary = '';
    foreach ($positions as $value) {
        $binary .= pack('g', (float) $value);
    }
    foreach ($indices as $value) {
        $binary .= pack('v', $value);
    }
    $document = [
        'asset' => ['version' => '2.0', 'generator' => 'TerraNova integration'],
        'extensionsUsed' => ['KHR_materials_unlit'],
        'scene' => 0,
        'scenes' => [['nodes' => [0]]],
        'nodes' => [['mesh' => 0]],
        'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1, 'material' => 0]]]],
        'materials' => [[
            'pbrMetallicRoughness' => ['baseColorFactor' => [0.76, 0.48, 0.12, 1]],
            'extensions' => ['KHR_materials_unlit' => new stdClass()],
        ]],
        'buffers' => [['byteLength' => strlen($binary)]],
        'bufferViews' => [
            ['buffer' => 0, 'byteOffset' => 0, 'byteLength' => count($positions) * 4, 'target' => 34962],
            ['buffer' => 0, 'byteOffset' => count($positions) * 4, 'byteLength' => count($indices) * 2, 'target' => 34963],
        ],
        'accessors' => [
            ['bufferView' => 0, 'componentType' => 5126, 'count' => 8, 'type' => 'VEC3', 'min' => [-1, -1, -1], 'max' => [1, 1, 1]],
            ['bufferView' => 1, 'componentType' => 5123, 'count' => count($indices), 'type' => 'SCALAR'],
        ],
    ];
    $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);
    $binary .= str_repeat("\0", (4 - strlen($binary) % 4) % 4);
    $length = 12 + 8 + strlen($json) + 8 + strlen($binary);
    $glb = 'glTF' . pack('V', 2) . pack('V', $length)
        . pack('V', strlen($json)) . pack('V', 0x4E4F534A) . $json
        . pack('V', strlen($binary)) . pack('V', 0x004E4942) . $binary;
    file_put_contents($fixture, $glb);
    $asset = $assets->store($scene, [
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fixture),
        'tmp_name' => $fixture,
        'name' => 'integration.glb',
    ], ['is_primary' => 1], $user);
    if (($asset['status'] ?? '') !== 'queued') {
        throw new RuntimeException('GLB asset was not queued.');
    }
    $mediaIds[] = (int) ($asset['media_id'] ?? 0);
    $storedPaths[] = BASE_PATH . '/public/' . ltrim((string) ($asset['uri'] ?? ''), '/');

    $stats = $processor->process(5);
    if ((int) ($stats['completed'] ?? 0) !== 1 || (int) ($stats['failed'] ?? 0) !== 0) {
        throw new RuntimeException('Spatial worker failed: ' . json_encode($stats));
    }

    $hotspot = $scenes->saveHotspot($sceneId, [
        'title' => 'Living room',
        'hotspot_type' => 'room',
        'position_x' => 0,
        'position_y' => 1,
        'position_z' => 0,
        'body' => 'Integration hotspot',
        'is_active' => 1,
    ]);
    if (empty($hotspot['public_id'])) {
        throw new RuntimeException('Hotspot creation failed.');
    }

    $published = $scenes->publish($sceneId);
    if (empty($published['ok'])) {
        throw new RuntimeException('Scene publication failed: ' . json_encode($published, JSON_UNESCAPED_UNICODE));
    }
    $public = $scenes->publicScene((string) $scene['public_id']);
    if (!$public || ($public['viewer']['model_url'] ?? '') === '' || count($public['hotspots'] ?? []) !== 1) {
        throw new RuntimeException('Public viewer payload is incomplete.');
    }
    if (!$scenes->recordEvent((string) $scene['public_id'], ['event_type' => 'load', 'source_page' => '/integration'])) {
        throw new RuntimeException('Spatial event was not recorded.');
    }

    $passed = true;
    echo "spatial module integration test passed\n";
    if ($keepFixture) {
        echo 'fixture_scene_id=' . $sceneId . "\n";
        echo 'fixture_url=http://127.0.0.1:8001/spatial/scene/' . $created['slug'] . "\n";
    }
} finally {
    if ($keepFixture && $passed) {
        return;
    }
    if ($sceneId > 0) {
        $pdo->prepare('DELETE FROM tn_spatial_scenes WHERE id = :id')->execute(['id' => $sceneId]);
    }
    foreach (array_filter($mediaIds) as $mediaId) {
        $pdo->prepare('DELETE FROM tn_media_assets WHERE id = :id')->execute(['id' => $mediaId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM tn_users WHERE id = :id')->execute(['id' => $userId]);
    }
    foreach ($storedPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_file($fixture)) {
        unlink($fixture);
    }
}
