<?php

namespace Modules\TgAdmin\Renderer;


use Modules\TgAdmin\Featuring\Messages;

class Translator{

    public array $t;
    public array $page_messages;
    public function __construct(){

    }
    public static function t(string $text): string{
        if(!$text) return ""; #TODO add exception

        $defaultLocale = 'ua';
        $lang_file = APP_PATH . "/modules/TgAdmin/Language/{$defaultLocale}.php";
        $library = file_exists($lang_file) ? require $lang_file : [];

        $const = !str_starts_with($text, 'TG_') ? 'TG_' . strtoupper($text) : $text;
        return mb_convert_encoding($library[$const] ?? $text,"UTF-8");
    }
    public static function pm($command, $page){
        if(!$page) return "";

        $defaultLocale = 'ua';
        $page_messages_file = APP_PATH . "/modules/TgAdmin/Language/{$defaultLocale}_page_message.php";
        $library = file_exists($page_messages_file) ? require $page_messages_file : [];
        return $library[$command][$page] ?? '';
    }
}
