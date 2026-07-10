<?php
declare(strict_types=1);

namespace Common\Models\Media;

use Phalcon\Mvc\Model;

class MediaRelation extends Model
{
    public $id;
    public $media_id;
    public $entity_type;
    public $entity_id;
    public $role;
    public $sort_order;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('tn_media_relations');
    }
}
