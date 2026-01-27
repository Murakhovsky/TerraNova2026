<?php
/**
 * Created by PhpStorm.
 * User: think
 * Date: 25.07.17
 * Time: 14:26
 */

namespace Modules\TgAdmin\Models\Estate;


class  ObjectsArchive extends Objects
{
    public function initialize()
    {
        $this->setSource('estate_archive');
    }

    public static function getTittle(array $data): ?string
    {
        $price = 'price_' . $data->currency;
        return constant('TG_' . $data->category) . ' ' . $data->rooms . 'кім '
            . (defined('TG_' . $data->type) ? constant('TG_' . $data->type) . ' ' : '') . 'по вул.' . $data->street
            . ($data->$price ? ', ' . $data->$price . $data->currency : '');
    }

    public static function getTGText(array $data, array $params = array())
    {
        // TODO: Implement getTGText() method.
    }

    public function getTGCard(int $user_id, array $params = array())
    {
        // TODO: Implement getTGCard() method.
    }
}