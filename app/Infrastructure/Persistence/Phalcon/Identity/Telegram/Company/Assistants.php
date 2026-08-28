<?php

namespace Infrastructure\Persistence\Phalcon\Identity\Telegram\Company;

use Infrastructure\Persistence\Phalcon\Identity\Telegram\BaseModel;

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



