<?php

namespace Modules\TgAdmin\Models\Service;
use Phalcon\Mvc\Model;

class Errors extends Model
{
    public $type;
    public $class;
    public $description;
    public $time;

//    public function initialize($data = array()){
//       $this->set($data);
//    }

    public function set($data)
    {
        $this->type = isset($data["type"]) ? $data["type"] : "Unknown type!";
        $this->class = isset($data["class"]) ? $data["class"] : "Unknown class!";
        $this->description = isset($data["desc"]) ? $data["desc"] : "Unknown error!";
        $this->time = isset($data["time"]) ? $data["time"] : date('Y-m-d H:i:s', time());
    }
}