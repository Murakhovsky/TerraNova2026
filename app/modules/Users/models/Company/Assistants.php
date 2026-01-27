<?php

namespace Modules\Users\Models\Company;

use Modules\Users\Models\BaseModel;

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
