<?php
declare(strict_types=1);

namespace Common\Models\Crm;

use Phalcon\Mvc\Model;

class ClientCasePropertyMatch extends Model
{
    public $id;
    public $client_case_id;
    public $property_id;
    public $match_status;
    public $score;
    public $note;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_client_case_property_matches');
    }
}
