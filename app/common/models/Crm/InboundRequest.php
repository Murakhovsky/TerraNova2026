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
    public $assigned_user_id;
    public $property_id;
    public $full_name;
    public $phone;
    public $email;
    public $role;
    public $deal_type;
    public $request_intent;
    public $message;
    public $manager_note;
    public $last_contacted_at;
    public $next_contact_at;
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
