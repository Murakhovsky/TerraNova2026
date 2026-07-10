<?php
declare(strict_types=1);

namespace Common\Models\Crm;

use Phalcon\Mvc\Model;

class ClientCaseActivity extends Model
{
    public $id;
    public $client_case_id;
    public $person_id;
    public $user_id;
    public $activity_type;
    public $title;
    public $body;
    public $due_at;
    public $completed_at;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('tn_client_case_activities');
    }
}
