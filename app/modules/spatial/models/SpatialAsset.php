<?php
declare(strict_types=1);

namespace Modules\Spatial\Models;

use Phalcon\Mvc\Model;

class SpatialAsset extends Model
{
    public $id;
    public $public_id;
    public $scene_id;
    public $version_id;
    public $capture_id;
    public $media_id;
    public $asset_type;
    public $format;
    public $storage;
    public $uri;
    public $status;
    public $is_primary;
    public $sort_order;
    public $size_bytes;
    public $checksum;
    public $metadata_json;
    public $error_message;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_spatial_assets');
    }
}
