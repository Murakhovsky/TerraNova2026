<?php

namespace Modules\Users\Models\Person;
use Modules\Users\Models\Company\Employees;
use Phalcon\Mvc\Model;

class ColdPhones extends Model
{
    public $id;
    public $name;
    public $phone;
    public $description;
    public $status;
    public $author_id;
    public $company_id;
    public $accountable_id;
    public $request_id;
    public $added_at;
    public $contacted_at;

    public function initialize()
    {
        $this->setSource('person_cold_phones');
    }
    public function getDataArray($data_id)
    {
        $lead = self::findFirst("id = '" . $data_id . "'");
        return $lead ? $lead->toArray() : array();
    }

    public static function getCountOf($company_id, $status = '_group_', $user_id = false, $period = 'all')
    {

        $data = array();
        $condition = 'company_id = :c_id:';
        $bind = array(
            'c_id' => $company_id
        );
        if ($status && $status != 'all') {
            if ($status == '_group_') {
                $data['group'] = 'status';
            } else {
                $condition .= ' AND status = :s:';
                $bind['s'] = $status;
            }
        }
        if ($user_id) {
            $condition .= ' AND accountable_id = :a_id:';
            $bind['a_id'] = $user_id;
        }
        if ($period != 'all') {
            $condition .= ' AND added_at > :c_at:';
            $bind['c_at'] = date('Y-m-d H:i:s', strtotime(' -1 ' . $period));
        }

        return self::count([
                $condition,
                'bind' => $bind,
            ] + $data);
    }

    public static function getFree($company_id, $count_leads = false)
    {
        $data = array(
            'status = :status: AND company_id = :company_id:',
            'bind' => [
                'status' => 'new',
                'company_id' => $company_id
            ]
        );
        if ($count_leads) {
            $data['limit'] = $count_leads;
        }
        return self::find($data);
    }

    public static function getFreeByUser($accountable_id, $count_leads = false)
    {
        $company_id = Employees::getCompanyByUserId($accountable_id);
        return $company_id ? self::getFree($company_id) : false;
    }

    public static function getLeads($user_id, $status = false, $company_id = false)
    {
        $data = array();
        $condition = 'accountable_id = :u_id:';

        $bind = array(
            'u_id' => $user_id
        );

        if ($status) {
            $condition .= ' AND status = :status:';
            $bind['status'] = $status;
        }

        if ($company_id) {
            $condition .= ' AND company_id = :c_id:';
            $bind['c_id'] = $company_id;
        }

        return self::find([
                $condition,
                'bind' => $bind,
            ] + $data);
    }
}