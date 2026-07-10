<?php
declare(strict_types=1);

namespace Common\Models\Media;

use Phalcon\Mvc\Model;

class MediaAsset extends Model
{
    public $id;
    public $public_id;
    public $kind;
    public $storage;
    public $original_name;
    public $mime_type;
    public $extension;
    public $size_bytes;
    public $storage_path;
    public $public_url;
    public $checksum;
    public $status;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_media_assets');
    }
}
