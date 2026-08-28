<?php

namespace Domains\Property\Infrastructure\Persistence\Phalcon\Telegram\Realty;
use Phalcon\Mvc\Model;

class RealtyReamak extends Model
{
    public $id;
    public $tittle;
    public $user_id;
    public $photos_url;

    public $category;
    public $type;
    public $status;
    public $status_data;

    public $price_UAH;
    public $price_USD;
    public $rooms;              // rooms

    public $square_full;
    public $square_dwelling;
    public $square_kitchen;
    public $square_ground;

    public $floor;             // floors
    public $floors;             // height

    public $condition;
    public $wall_material;       // wall_material
    public $object_type;
    public $heating;
    public $is_owner;
    public $description;

    public $address;
    public $city;
    public $street;

    public $updated_at;        // updated_at_at
    public $published_at;
    public $changed_at;

    public $contact_name;
    public $contact_phone;
    public $provider;

    public function setData($data)
    {
        if((int)$data['id']){$this->id = $data['id'];}
        $this->tittle = $data['tittle'] ?? '';
        $this->user_id = $data['user_id'] ?? '';
        $this->photos_url = $data['photos_url'] ?? '';
        $this->price_USD = $data['price_USD'] ?? '';
        $this->price_UAH = $data['price_UAH'] ?? '';
        $this->rooms = $data['rooms'] ?? '';
        $this->square_full = $data['square_full'] ?? '';
        $this->square_dwelling = $data['square_dwelling'] ?? '';
        $this->square_kitchen = $data['square_kitchen'] ?? '';
        $this->square_ground = $data['square_ground'] ?? '';
        $this->floor = $data['floor'] ?? '';
        $this->floors = $data['floors'] ?? '';
        $this->type = $data['type'] ?? '';
        $this->condition = $data['condition'] ?? '';
        $this->wall_material = $data['wall_material'] ?? '';
        $this->object_type = $data['object_type'] ?? '';
        $this->heating = $data['heating'] ?? '';
        $this->is_owner = $data['is_owner'] ?? '';
        $this->description = isset($data['description']) ? strip_tags($data['description']) : 'No description';
        $this->address = $data['address'] ?? '';
        $this->city = $data['city'] ?? '';
        $this->street = $data['street'] ?? '';
        $this->published_at = $data['published_at'] ?? date('Y-m-d H:i:s');
        $this->updated_at = $data['updated_at'] ?? '';
        $this->changed_at = date('Y-m-d H:i:s');
        $this->contact_name = $data['contact_name'] ?? '';
        $this->contact_phone = $data['contact_phone'] ?? '';
        $this->provider = $data['provider'] ?? '';
        $this->category = $data['category'] ?? '';
        $this->type = $data['type'] ?? '';
        $this->status = $data['status'] ?? '';
        $this->status_data = $data['status_data'] ?? '';

    }

    public function getAdvertByID($realty_id){
        $data = array(
            'conditions' => 'id = :id:',
            'bind' => array('id' => $realty_id)
        );
        return self::findFirst($data);
    }

    public static function getCountOf($company_id, $status = '_group_', $user_id = false, $period = 'all')
    {

        $data = array();
        $condition = '';
        $bind = array();
//        $condition = 'company_id = :c_id:';
//        $bind = array(
//            'c_id' => $company_id
//        );
        if ($status && $status != 'all') {
            if ($status == '_group_') {
                $data['group'] = 'status';
            } else {
                if ($condition !== '') {
                    $condition .= ' AND ';
                }
                $condition .= 'status = :s:';
                $bind['s'] = $status;
            }
        }
        if ($user_id) {
            if ($condition !== '') {
                $condition .= ' AND ';
            }
            $condition .= 'user_id = :a_id:';
            $bind['a_id'] = $user_id;
        }
        if ($period != 'all') {
            if ($condition !== '') {
                $condition .= ' AND ';
            }
            $condition .= 'added_at > :c_at:';
            $bind['c_at'] = date('Y-m-d H:i:s', strtotime(' -1 ' . $period));
        }

        return self::count([
                $condition,
                'bind' => $bind,
            ] + $data);
    }
}
