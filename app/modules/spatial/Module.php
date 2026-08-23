<?php
declare(strict_types=1);

namespace Modules\Spatial;

use Modules\Spatial\Services\SpatialAccessService;
use Modules\Spatial\Services\SpatialAssetService;
use Modules\Spatial\Services\SpatialProcessingService;
use Modules\Spatial\Services\SpatialSceneService;
use Phalcon\Autoload\Loader;
use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null): void
    {
        $loader = new Loader();
        $loader->setNamespaces([
            'Modules\Spatial\Controllers' => __DIR__ . '/controllers/',
            'Modules\Spatial\Models' => __DIR__ . '/models/',
            'Modules\Spatial\Services' => __DIR__ . '/services/',
        ]);
        $loader->register();
    }

    public function registerServices(DiInterface $di): void
    {
        $router = $di->getShared('router');
        $route = static fn(string $method, string $path, string $action) => $router->{'add' . $method}($path, [
            'namespace' => 'Modules\Spatial\Controllers',
            'module' => 'spatial',
            'controller' => 'api',
            'action' => $action,
        ]);

        $route('Post', '/api/spatial/auth/token', 'token');
        $route('Get', '/api/spatial/scenes/{publicId:[A-Za-z0-9-]+}', 'scene');
        $route('Post', '/api/spatial/scenes', 'saveScene');
        $route('Post', '/api/spatial/scenes/{id:[0-9]+}/assets', 'uploadAsset');
        $route('Post', '/api/spatial/scenes/{id:[0-9]+}/external-assets', 'externalAsset');
        $route('Post', '/api/spatial/scenes/{id:[0-9]+}/captures', 'capture');
        $route('Post', '/api/spatial/scenes/{id:[0-9]+}/hotspots', 'saveHotspot');
        $route('Post', '/api/spatial/scenes/{id:[0-9]+}/publish', 'publish');
        $route('Get', '/api/spatial/jobs/{publicId:[A-Za-z0-9-]+}', 'job');
        $route('Post', '/api/spatial/events', 'event');

        $di->setShared('spatialAccessService', function () {
            return new SpatialAccessService(
                $this->getShared('databaseService'),
                $this->getShared('authService'),
                (string) $this->getShared('config')->spatial->jwt_secret,
                (int) $this->getShared('config')->spatial->jwt_ttl
            );
        });
        $di->setShared('spatialAssetService', function () {
            return new SpatialAssetService(
                $this->getShared('databaseService'),
                (int) $this->getShared('config')->spatial->max_upload_bytes
            );
        });
        $di->setShared('spatialSceneService', function () {
            return new SpatialSceneService(
                $this->getShared('databaseService'),
                $this->getShared('spatialAssetService')
            );
        });
        $di->setShared('spatialProcessingService', function () {
            return new SpatialProcessingService(
                $this->getShared('databaseService'),
                (string) $this->getShared('config')->spatial->blender_binary,
                (string) $this->getShared('config')->spatial->gltf_transform_binary
            );
        });
    }
}
