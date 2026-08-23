<?php
declare(strict_types=1);

namespace Modules\Spatial\Models;

use Phalcon\Mvc\Model;

class SpatialRelation extends Model
{
    public $id;
    public $scene_id;
    public $entity_type;
    public $entity_id;
    public $role;
    public $sort_order;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('tn_spatial_relations');
    }
}
