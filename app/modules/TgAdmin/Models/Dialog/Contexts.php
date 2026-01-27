<?php

namespace Modules\TgAdmin\Models\Dialog;

use Phalcon\Mvc\Model;

class Contexts extends Model
{
    public int $id;
    public string $name;
    public string $description;
    public ?string $default_entities;
    public ?string $possible_intents; // commands, requests, communication, FAQ,
    public ?string $example_phrases;
    public string $created_at;
    public string $updated_at;

    public function initialize()
    {
        $this->setSource('dialog_contexts');

        $this->hasMany(
            'id',
            Dialogs::class,
            'contest_id',
            [
                'alias' => 'dialog_contexts'
            ]
        );
    }

    public function beforeSave()
    {
        if (is_array($this->default_entities)) {
            $this->default_entities = json_encode($this->default_entities);
        }

        if (is_array($this->possible_intents)) {
            $this->possible_intents = json_encode($this->possible_intents);
        }
    }

    public function afterFetch()
    {
        $this->default_entities = json_decode($this->default_entities, true);
        $this->possible_intents = json_decode($this->possible_intents, true);
    }
}
