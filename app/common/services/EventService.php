<?php

namespace Common\Services;

use Phalcon\Di\Injectable;
use Phalcon\Events\Manager;

class EventService extends Injectable
{
    protected Manager $eventsManager;

    public function __construct()
    {
        $this->eventsManager = new Manager();
    }

    /**
     * Виклик події
     */
    public function fire(string $eventName, $source, array $data = [])
    {
       return $this->eventsManager->fire($eventName, $source, $data);
    }

    /**
     * Прив'язка слухача
     */
    public function attach(string $eventName, $listener):void
    {
        $this->eventsManager->attach($eventName, $listener);
    }

    public function getManager(): Manager
    {
        return $this->eventsManager;
    }
}
