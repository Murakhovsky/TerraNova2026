<?php


namespace Modules\Users\Models\Person;

use Phalcon\Mvc\Model;

class UsersLikes extends Model
{
    public $id;
    public $who_id;
    public $whom_id;
    public $rating;
    public $created_at;

    public function initialize()
    {
        $this->setSource('person_users_likes');
    }
}