<?php
declare(strict_types=1);

namespace Common\Models\Crm;

use Phalcon\Mvc\Model;

class ClientCaseRequestMatch extends Model
{
    public $id;
    public $client_case_id;
    public $inbound_request_id;
    public $relation_type;
    public $note;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('tn_client_case_request_matches');
    }
}
