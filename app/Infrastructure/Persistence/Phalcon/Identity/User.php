<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\Phalcon\Identity;

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
