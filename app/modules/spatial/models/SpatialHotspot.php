<?php
declare(strict_types=1);

namespace Modules\Spatial\Models;

use Phalcon\Mvc\Model;

class SpatialHotspot extends Model
{
    public $id;
    public $public_id;
    public $scene_id;
    public $version_id;
    public $hotspot_type;
    public $title;
    public $body;
    public $icon;
    public $position_json;
    public $target_json;
    public $action_url;
    public $sort_order;
    public $is_active;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_spatial_hotspots');
    }
}
