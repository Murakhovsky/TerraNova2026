<?php
declare(strict_types=1);

namespace Modules\Spatial\Models;

use Phalcon\Mvc\Model;

class SpatialProcessingJob extends Model
{
    public $id;
    public $public_id;
    public $scene_id;
    public $asset_id;
    public $job_type;
    public $status;
    public $attempts;
    public $progress;
    public $available_at;
    public $locked_at;
    public $lock_token;
    public $input_json;
    public $output_json;
    public $error_message;
    public $started_at;
    public $completed_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_spatial_processing_jobs');
    }
}
