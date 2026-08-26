<?php
declare(strict_types=1);

namespace Modules\Spatial\Models;

use Phalcon\Mvc\Model;

class SpatialScene extends Model
{
    public $id;
    public $public_id;
    public $slug;
    public $title;
    public $description;
    public $scene_type;
    public $viewer_type;
    public $provider;
    public $status;
    public $external_url;
    public $poster_media_id;
    public $default_camera_json;
    public $settings_json;
    public $created_by_user_id;
    public $published_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_spatial_scenes');
    }
}
