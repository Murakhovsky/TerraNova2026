<?php
declare(strict_types=1);

namespace Modules\Spatial\Models;

use Phalcon\Mvc\Model;

class SpatialCapture extends Model
{
    public $id;
    public $public_id;
    public $scene_id;
    public $version_id;
    public $capture_type;
    public $provider;
    public $external_id;
    public $device_name;
    public $status;
    public $source_payload;
    public $captured_by_user_id;
    public $captured_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_spatial_captures');
    }
}
