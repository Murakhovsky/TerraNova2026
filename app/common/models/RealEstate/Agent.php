<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class Agent extends Model
{
    public $id;
    public $public_name;
    public $role;
    public $phone;
    public $email;
    public $telegram;
    public $avatar_url;
    public $bio;
    public $is_active;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_agents');
    }
}
