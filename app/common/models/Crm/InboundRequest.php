<?php
declare(strict_types=1);

namespace Common\Models\Crm;

use Phalcon\Mvc\Model;

class InboundRequest extends Model
{
    public $id;
    public $buyer_id;
    public $person_id;
    public $client_case_id;
    public $property_id;
    public $full_name;
    public $phone;
    public $email;
    public $role;
    public $deal_type;
    public $message;
    public $preferred_contact;
    public $source_page;
    public $status;
    public $utm_source;
    public $utm_medium;
    public $utm_campaign;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_leads');
    }
}
