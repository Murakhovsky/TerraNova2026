<?php
declare(strict_types=1);

namespace Common\Models\Crm;

use Phalcon\Mvc\Model;

class Person extends Model
{
    public $id;
    public $public_id;
    public $full_name;
    public $phone;
    public $email;
    public $telegram;
    public $notes;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_people');
    }
}
