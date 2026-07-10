<?php
declare(strict_types=1);

namespace Common\Models\Auth;

use Phalcon\Mvc\Model;

class User extends Model
{
    public $id;
    public $email;
    public $password_hash;
    public $full_name;
    public $phone;
    public $role;
    public $status;
    public $last_login_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_users');
    }
}
