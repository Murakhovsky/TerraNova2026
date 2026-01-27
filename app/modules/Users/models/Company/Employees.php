<?php

namespace Modules\Users\Models\Company;

use Modules\Users\Models\BaseModel;
use Modules\Users\Models\Person\AppUsers;


class Employees extends BaseModel {
    public $user_id;
    public $company_id;
    public $position;
    public $status;

    public function initialize()
    {
        $this->setSource('company_employees');
    }
    public function setDataFromConversation($notes){
//        $this->id = $notes["id"];
        $this->user_id = $notes["user_id"];
        $this->company_id = $notes["company_id"];
        $this->position = $notes["position"] ?? '';
        $this->status = $notes["status"] ?? '';
    }

    static function getDataArray($id){
        $userData = self::findFirst("id='".$id."'");

        if(!$userData){return array();}
        return (array) $userData;
    }

//    static function getCompanyId($user_id){
//        return self::findFirst('id = "' . $user_id . '"')->company_id;
//    }

    static function getCompanyByUserId($user_id){
        return self::findFirst('user_id = "' . $user_id . '"')->company_id;
    }

    static function getNewEmployees($company_id){

        $users = AppUsers::find('company_id = "' . $company_id . '"');

        $employees = Employees::find('company_id = "' . $company_id . '"');
        $employees_id = array();
        $employees_new = array();
        foreach ($employees as $employee){
            $employees_id[] = $employee->user_id;
        }

        foreach ($users as $user) {
            if (!in_array($user->id, $employees_id)){
                $employees_new[] = $user;
            }
        }
//        Messages::sendMeMessage(count($employees_new));
        return $employees_new;
    }

    static public function is_employees_new($employee_id, $company_id){
        $users = AppUsers::find('id = "' . $employee_id . '" AND company_id = "' . $company_id . '"');
        if (!$users) return false;

        $employees = Employees::find('user_id = "' . $employee_id . '" AND company_id = "' . $company_id . '"');
        if ($employees) return false;

        return true;
    }

    static function getEmployeesJoinUsers($company_id)
    {

        $user_model = 'Persons\AppUsers';
        $employees_model = 'Company\Employees';
        $queryBuilder = new \Phalcon\Mvc\Model\Query\Builder();

        $queryBuilder->columns([
            $user_model . '.id',
            $user_model . '.status',
            $user_model . '.first_name',
            $user_model . '.last_name',
            $user_model . '.username',
            $user_model . '.where_from',
            $user_model . '.company_id',
            $user_model . '.language_code',
            $user_model . '.published_at',
            $user_model . '.what_can',
            $user_model . '.what_need',
            $user_model . '.photo',
            $user_model . '.contact_phone',
            $employees_model . '.position',
            $employees_model . '.company_id as e_company_id',
            $employees_model . '.status as e_status',
            $employees_model . '.added_at',
        ])
            ->from($user_model)
            ->leftJoin(
                $employees_model,
                $user_model . '.id = ' . $employees_model . '.user_id'
            )
            ->where(
                $user_model . '.company_id = :company_id:',
                ['company_id' => $company_id]
            )
            ->orderBy([
                'e_company_id',
                'added_at DESC',
            ]);
        $company_employees_data = $queryBuilder->getQuery()->execute();

        return $company_employees_data;
    }

    static function getEmployeeJoinUser($user_id)
    {

        $user_model = 'Persons\AppUsers';
        $employees_model = 'Company\Employees';
        $queryBuilder = new \Phalcon\Mvc\Model\Query\Builder();

        $queryBuilder->columns([
            $user_model . '.id',
            $user_model . '.status',
            $user_model . '.first_name',
            $user_model . '.last_name',
            $user_model . '.username',
            $user_model . '.where_from',
            $user_model . '.company_id',
            $user_model . '.language_code',
            $user_model . '.published_at',
            $user_model . '.what_can',
            $user_model . '.what_need',
            $user_model . '.photo',
            $user_model . '.contact_phone',
            $employees_model . '.position',
            $employees_model . '.company_id as e_company_id',
            $employees_model . '.status as e_status',
            $employees_model . '.added_at',
        ])
            ->from($user_model)
            ->leftJoin(
                $employees_model,
                $user_model . '.id = ' . $employees_model . '.user_id'
            )
            ->where(
                $user_model . '.id = :user_id:',
                ['user_id' => $user_id]
            );
        $company_employees_data = $queryBuilder->getQuery()->execute();

        return $company_employees_data;
    }

    static function getMyEmployees($user_id, $positions = array(), $status = false){

        $condition = 'company_id = :c_id:';
        $bind = ['c_id' => self::getCompanyByUserId($user_id)];

        if ($positions){
            $condition .= ' AND (';
            foreach ($positions as $key => $position){

                if($key != 0){
                    $condition .= ' OR ';
                }
                $condition .= 'position = :p' . $key . ':';
                $bind['p'.$key] = $position;
            }
            $condition .= ')';
        }

        if ($status){
            $condition .= ' AND status = :s:';
            $bind['s'] = $status;
        }

        return self::find([
            $condition,
            'bind' => $bind,
//            'order' => 'added_at DESC',
        ]);

    }

    static function getEmployeeIfAdmin($user_id){
        return self::findFirst([
            'user_id = :user_id: AND position = :position:',
            'bind' => [
                'user_id' => $user_id,
                'position' => 'admin'
            ],
        ]);
    }

    static function getRealtorsByCompany($company_id){
        return self::find([
            'company_id = :company_id: AND (position = :p1: OR position = :p2:)',
            'bind' => [
                'company_id' => $company_id,
                'p1' => 'realtor',
                'p2' => 'manager',
            ],
        ]);
    }

    public static function getTittle(array $data): ?string
    {
        return "test";
        // TODO: Implement getTittle() method.
    }

    public static function getTGText(array $data, array $params = array())
    {
        // TODO: Implement getTGText() method.
    }

    public function getTGCard(int $user_id, array $params)
    {
        // TODO: Implement getTGCard() method.
    }
}