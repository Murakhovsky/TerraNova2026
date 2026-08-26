<?php
declare(strict_types=1);

namespace Modules\Spatial\Models;

use Phalcon\Mvc\Model;

class SpatialVersion extends Model
{
    public $id;
    public $scene_id;
    public $version_number;
    public $label;
    public $capture_method;
    public $status;
    public $device_name;
    public $captured_at;
    public $notes;
    public $metadata_json;
    public $created_by_user_id;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_spatial_versions');
    }
}
