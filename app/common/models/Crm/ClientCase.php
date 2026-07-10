<?php
declare(strict_types=1);

namespace Common\Models\Crm;

use Phalcon\Mvc\Model;

class ClientCase extends Model
{
    public $id;
    public $public_id;
    public $person_id;
    public $type;
    public $title;
    public $status;
    public $stage;
    public $priority;
    public $assigned_user_id;
    public $source;
    public $inbound_request_id;
    public $property_type_id;
    public $location_id;
    public $budget_min;
    public $budget_max;
    public $currency;
    public $area_min;
    public $area_max;
    public $description;
    public $parameters_json;
    public $started_at;
    public $next_contact_at;
    public $closed_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_client_cases');
    }
}
