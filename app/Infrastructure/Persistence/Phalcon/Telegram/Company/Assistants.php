<?php

namespace Infrastructure\Persistence\Phalcon\Telegram\Company;


use Infrastructure\Persistence\Phalcon\Telegram\Featuring\BaseModel;
use Phalcon\Mvc\Model;

class Assistants extends BaseModel
{
    public ?string $name = null;
    public string $api_key; // унікальний ідентифікатор проекту
    public string $ass_key; // унікальний ідентифікатор асистента
    public ?string $model = null;

    public function initialize()
    {
        $this->setSource('company_assistants');
    }

}

