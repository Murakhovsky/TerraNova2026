<?php

namespace Modules\TgAdmin\Models\Dialog;

use Modules\TgAdmin\Models\Featuring\BaseModel;
use OpenAI\Exceptions\ErrorException;
use Modules\TgAdmin\Featuring\Messages;
use Modules\TgAdmin\Models\Company\Assistants;
use Modules\TgAdmin\Models\Person\AppUsers;
use Phalcon\Mvc\Model;

class Dialogs extends BaseModel
{
    public int $assistant_id;
    public int $user_id;
    public ?int $context_id = null;
    public string $chat_id;
    public string $thread_id; // ідентифікатор гілки в діалозі
    public string $status = "active"; // статус асистента, наприклад, 'active', 'inactive'

//    public ?string $last_intent = null;
//    public ?string $last_response = null;
    public ?string $entities = null;
    public ?string $additional_data = null;

    public function initialize()
    {
        define("INVITE_ASSISTANT_ID", 3);
        $this->setSource('dialog_dialogs');

        $this->belongsTo(
            'assistant_id',
            Assistants::class,
            'id',
            [
                'alias' => 'company_assistants'
            ]
        );

        $this->belongsTo(
            'user_id',
            AppUsers::class,
            'id',
            [
                'alias' => 'person_users'
            ]
        );

        $this->belongsTo(
            'context_id',
            Contexts::class,
            'id',
            [
                'alias' => 'dialog_contexts'
            ]
        );
        $this->hasMany(
            'id',
            Commands::class,
            'dialog_id',
            [
                'alias' => 'dialog_commands'
            ]
        );

    }

    public function beforeSave()
    {
        if (is_array($this->entities)) {
            $this->entities = json_encode($this->entities);
        }

        if (is_array($this->additional_data)) {
            $this->additional_data = json_encode($this->additional_data);
        }
    }

    public function afterFetch()
    {
        $this->entities = json_decode($this->entities, true);
        $this->additional_data = json_decode($this->additional_data, true);
    }

    public static function get_current_dialog($user_id)
    {
        return self::findFirst(
            [
                'conditions' => 'user_id = ?1 AND status = ?2 AND assistant_id =?3',
                'bind' => [
                    1 => $user_id,
                    2 => 'active',
                    3 => INVITE_ASSISTANT_ID
                ],
                'order' => 'updated_at DESC'
            ]
        );
    }

    public function set_dialog($user_id, $chat_id, $thread_id){
        $this->assign([
            'assistant_id' => INVITE_ASSISTANT_ID,
            'user_id' => $user_id,
//                'context_id' => 1,
            'chat_id' => $chat_id,
            'thread_id' => $thread_id
        ]);

        if(!$this->save()){
            throw new ErrorException([
                "message" => implode('; ', $this->getMessages()),
                "code" => "save_dialog_error"
            ]);
        };

    }

}
