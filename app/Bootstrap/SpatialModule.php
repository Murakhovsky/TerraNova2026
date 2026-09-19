<?php
declare(strict_types=1);

namespace Bootstrap;

use Infrastructure\Media\SpatialAssetService;
use Infrastructure\Spatial\SpatialProcessingService;
use Domains\Spatial\Application\Service\SpatialSceneService;
use Domains\Spatial\Infrastructure\Persistence\MySql\MysqlSpatialSceneRepository;
use Domains\Property\Infrastructure\Spatial\CanonicalPropertyTourPublisher;
use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;

class SpatialModule implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null): void
    {
        // Canonical namespaces are loaded through Composer.
    }

    public function registerServices(DiInterface $di): void
    {
        // Spatial HTTP API ownership moved to Symfony. This module remains
        // temporarily only as composition for legacy SSR Spatial/Web consumers.
        $di->setShared('spatialAssetService', function () {
            return new SpatialAssetService(
                $this->getShared('databaseService'),
                (int) $this->getShared('config')->spatial->max_upload_bytes
            );
        });
        $di->setShared('spatialSceneRepository', function () {
            return new MysqlSpatialSceneRepository(
                $this->getShared('databaseService'),
                $this->getShared('spatialAssetService'),
                new CanonicalPropertyTourPublisher(
                    $this->getShared('propertyCanonicalRuntime'),
                    $this->getShared('organizationContext')->id(),
                ),
            );
        });
        $di->setShared('spatialSceneService', function () {
            return new SpatialSceneService($this->getShared('spatialSceneRepository'));
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
