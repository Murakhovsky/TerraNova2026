<?php


namespace Modules\Users\Models;

use common\exceptions\DBException;
use Longman\TelegramBot\Exception\TelegramException;
use Modules\TgAdmin\Renderer\Messages;
use Modules\Users\Models\Estate\Objects;
use Modules\Users\Services\UsersService;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Query;
use Phalcon\Di\Di;

abstract class BaseModel extends Model
{
    public ?int $id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    protected string $newSavedServiceName;
//

    public function setDataFromConversation($notes){// deprecated
        foreach($notes as $key => $value) {
            if(property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }


    public static function executeQuery($phql, $params = []){
        $query = new Query(
            $phql,
            Di::getDefault()
        );
        return $query->execute($params);
    }

    public function saveModel(): ?int
    {

        $is_new = !($this->id && self::findFirst($this->id));

        if (!$this->save()) {
            $mess = '';
            foreach ($this->getMessages() as $msg) {
                $mess .= $msg . ', ';
            }
            throw new DBException('Не вдалося зберегти дані в базу (' . $this->usage . ')' . PHP_EOL . $mess);
        }
        #todo реалізувати DTO
        // DTO (Data Transfer Object) дозволяє:
        // - передавати тільки ті дані, які потрібні для обробки події
        // - ізолюватися від змін у моделі User, які не повинні впливати на логіку подій
        // $payload = new ProfileCompletedDTO($user);
        // $eventService->fire('user:profileCompleted', $payload);
        $eventService = di('eventService');

        if ($is_new) {
            $eventService->fire($this->newSavedServiceName, $this);
        }
        return $this->id;
    }
}