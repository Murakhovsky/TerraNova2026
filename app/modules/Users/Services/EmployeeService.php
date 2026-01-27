<?php

namespace Modules\Users\Services;

use Modules\Users\Models\Company\Employees;

class EmployeeService extends AbstractService
{
   public function initialize()
   {
       $this->modelClass = Employees::class;
   }
    public function dismissEmployee() // звільнити працівника
    {

    }
}