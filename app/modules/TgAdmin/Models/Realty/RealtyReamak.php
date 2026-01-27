<?php

namespace Modules\TgAdmin\Models\Realty;
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

    public function show()
    {
        echo '<br>';
        var_dump('Tittle: '.$this->tittle); echo '<br>';
        echo 'Img Link: '.$this->img_links.'<br>';
        echo ' User ID: '.$this->user_id.'<br>';
        echo ' Price USD: '.$this->price_USD.'<br>';
        echo ' Price UAH: '.$this->price_UAH.'<br>';
        echo ' Count room: '.$this->rooms.'<br>';
        echo ' Square full: '.$this->square_full.'<br>';
        echo ' Square dwelling: '.$this->square_dwelling.'<br>';
        echo ' Square kitchen: '.$this->square_kitchen.'<br>';
        echo ' Square ground: '.$this->square_ground.'<br>';
        echo ' Storey: '.$this->floor.'<br>';
        echo ' Count storeys: '.$this->floors.'<br>';
        echo ' Type: '.$this->type.'<br>';
        echo ' Ground type: '.$this->type_ground.'<br>';
        echo ' Condition: '.$this->condition.'<br>';
        echo ' Wall material: '.$this->wall_material.'<br>';
        echo ' Balcony: '.$this->balconies.'<br>';
        echo ' Is owner: '.$this->is_owner.'<br>';
        echo ' Params: '.$this->params.'<br>';
        var_dump(' Description: '.$this->description); echo '<br>';
        echo ' Location code: '.$this->location_code.'<br>';
        echo ' City: '.$this->city.'<br>';
        echo ' Region: '.$this->region.'<br>';
        echo ' Latitude: '.$this->latitude.'<br>';
        echo ' Longitude: '.$this->longitude.'<br>';
        echo ' Advert code: '.$this->advert_code.'<br>';
        echo ' Published: '.$this->published_at.'<br>';
        echo ' Update: '.$this->updated_at.'<br>';
        echo ' Base URL: '.$this->base_url.'<br>';
        echo ' Advert link: '.$this->advert_link.'<br>';
        echo ' Contact name: '.$this->contact_name.'<br>';
        echo ' Contact linc: '.$this->contact_link.'<br>';
        echo ' Contact phone: '.$this->contact_phone.'<br>';
        echo ' Contact agency: '.$this->contact_agency.'<br>';
        echo ' Provider: '.$this->provider.'<br>';
        echo ' Status: '.$this->status.'<br>';
        echo ' Status data: '.$this->status_data.'<br>';

//        exit();
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
                $condition = $condition ?? $condition . ' AND ';
                $condition .= 'status = :s:';
                $bind['s'] = $status;
            }
        }
        if ($user_id) {
            $condition = $condition ?? $condition . ' AND ';
            $condition .= 'user_id = :a_id:';
            $bind['a_id'] = $user_id;
        }
        if ($period != 'all') {
            $condition = $condition ?? $condition . ' AND ';
            $condition .= 'added_at > :c_at:';
            $bind['c_at'] = date('Y-m-d H:i:s', strtotime(' -1 ' . $period));
        }

        return self::count([
                $condition,
                'bind' => $bind,
            ] + $data);
    }
}