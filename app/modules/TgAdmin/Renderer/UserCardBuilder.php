<?php

namespace Modules\TgAdmin\Renderer;
use Modules\TgAdmin\Models\Service\Lists;
use Modules\Users\Models\Person\UsersLikes;

class UserCardBuilder extends BaseCardBuilder
{

    public function __construct($notes)
    {
        $this->name = 'user';
        parent::__construct($notes);
    }


    public function getTittle(): string
    {
        return $this->notes["first_name"] . ' ' . $this->notes["last_name"];
    }
    public function getTGText(array $params = array()): string
    {
//        $employee = TelegramEbEmployees::findFirst("user_id = '" . $this->id . "'");
//        $company_name = $employee ? Messages::getTittle('company', $employee->company_id) : false;

        $text = $this->notes['first_name'] ? '🏆  ' . $this->notes['first_name'] . ' ' . $this->notes['last_name'] . PHP_EOL : '';
        $text .= $this->notes['where_from'] ? '🗺  ' . $this->t($this->notes['where_from']) . PHP_EOL : '';

//        $text .= $this->notes['who_are'] ? '📜  ' . (constant("TG_" . $this->notes['who_are']) ?? $this->notes['who_are']) . PHP_EOL : '';
//        $text .= '📤  ' . $this->notes['what_can'] . ($this->notes['what_need'] ? PHP_EOL . ' 📥  ' . $this->notes['what_need'] : '') . PHP_EOL;
        $text .= $this->notes['contact_phone'] ? '📱  ' . preg_replace('/\+38(\d{3})(\d{3})(\d{2})(\d{2})/', '+38($1)$2-$3-$4', $this->notes['contact_phone']) . PHP_EOL : '';
        $text .= $this->notes["photo"] ? '🖼  Фото додано<a href="' . DOMAIN_NAME . $this->notes["photo"] . '">.</a>' . PHP_EOL : '';
//        $text .= $company_name ? '🧰 Працює в ' . $company_name . PHP_EOL : '';

        return mb_convert_encoding($text, "UTF-8");
    }
    public function getTGCard(int $user_id, array $params)
    {
//        $company = TelegramEbCompany::findFirst("admin_id = '" . $user_id . "'");

        $message = $this::getTGText((array)$this, $params);
        $message .= ''
            . PHP_EOL. '✅  @estatebookgroup  #' . $this->where_from
            . '<a href="' . DOMAIN_NAME . $this->photo . '">.</a>'. PHP_EOL;

        // message buttons
        $inline_keyboard = array();

        // likes line
        $user_likes = UsersLikes::findFirst([
            'who_id = :who_id: AND whom_id = :whom_id:',
            'bind' => [
                'who_id' => $user_id,
                'whom_id' => $this->id,
            ],
        ]);

//        $inline_keyboard[] = Buttons::get_like_line($user_likes ? $user_likes->rating : 0, $this->id);

        // company line
//        $my_company = TelegramEbCompany::findFirst("admin_id = '" . $user_id . "'");
//        if($my_company) {
////            $inline_keyboard[] = null;
//            $employee_line = Buttons::getEmployerLine($my_company->id, $this);
//            if ($employee_line) {
//                $inline_keyboard[] = $employee_line;
//            }
//        }

        // control line
        $inline_keyboard[] = Buttons::getControlLine(
            'user,'     // type of message
            . $this->id    // user ID
        );


        return array(
            "parse_mode" => 'HTML',
            "message_text" => $message,
            "text" => $message,
            "reply_markup" => new InlineKeyboard(... $inline_keyboard)
        );
    }
     public function getDescription()
    {
        return constant('TG_' . $this->notes["where_from"]) ?? $this->notes["where_from"];
    }
    public function getThumbUrl()
    {
        return $this->notes["photo"] ?? '/TelegramFiles/Download/thumbnails/user.jpg';
    }

    public function render(): array{
        $result = [
//            'text'       => "**{$this->title}**\n\n{$this->text}",
            'reply_markup' => [
                'inline_keyboard' => array_chunk(
                    array_map(fn($b) => [$b], $this->buttons),
                    2
                )
            ]
        ];

        if ($this->photo) {
            // якщо є фото — повернути як photo message
            return [
                'photo'      => $this->photo,
                'caption'    => $result['text'],
                'reply_markup' => $result['reply_markup']
            ];
        }

        return $result;
    }

//    public function setPhoto(string $fileIdOrUrl): self
//    {
//        $this->photo = $fileIdOrUrl;
//        return $this;
//    }

    protected function getInlineData($params = array()):array{
        $result = array();
//        Messages::sendMeMessage("test1");
        $result["title"] = $this->getTittle((array)$this);
//        Messages::sendMeMessage("test2");
        $result["description"] = $this->getDescription();
//        Messages::sendMeMessage("test3");
        if($thumbURL = $this->getThumbUrl()) {
            $result["thumb_url"] = str_contains($thumbURL, "https:") ? $thumbURL : DOMAIN_NAME . $thumbURL;
        }
//        Messages::sendMeMessage("test4");
        $TGCard = $this->getTGCard($params["user_id"], $params);
//        Messages::sendMeMessage("test5");exit;
        return $result + $TGCard;
    }

    public function getPreOutMessage($params = array()): string{

        $text = $this->getTGText();
        $s_message = $this->pm($this->name, $this->notes['state']);

        $suffix = PHP_EOL . PHP_EOL  . '-------------------'. PHP_EOL;
        $suffix .= $s_message;
//        $suffix .= $this->notes['state'] == 'send' ? sprintf($s_message, $this->notes['end'], $params["search_id"]) : $s_message;

        return mb_convert_encoding( $text . $suffix, "UTF-8");
    }


}