<?php
namespace Modules\TgAdmin\Renderer;


use Modules\TgAdmin\Models\Featuring\BaseModel;
use Telegram\Commands\SystemCommands\InlinequeryCommand;

abstract class BaseCardBuilder{
    protected string $title   = '';
    protected string $text    = '';


    protected string $name;
    protected BaseModel $model_data;
    protected array $translate_data;

    protected array $notes;

//    protected

    public function __construct(array $notes)
    {
        $this->notes = $notes;
        $this->translate_data = array(
            'sale' => 'Продається',
            'rent' => 'Здається в оренду',
            'flat' => 'квартира',
            'house' => 'будинок',
            'ground' => 'ділянка',
            'commerce' => 'комерція',
            'floor' => 'поверх',
            'square' => 'площа',
            'r' => ' кім.',
            'r+' => ' кім. і більше',
            '1f'        => 'Перший',
            '2f'        => 'Від 2го',
            '4f'        => 'До 4го',
            '5f'        => 'Від 5го',
            '9f'        => 'До 9го',
            '10f'       => 'Від 10го.',
            'mf'        => 'Останній',
            'nmf'       => 'Не останній',
            '1r'        => '1 кім.',
            '12r'       => '1 або 2 кім.',
            '23r'       => '2 або 3 кім.',
        );

    }
    protected static function getTittle2($data, $param = null){
//        $data = TelegramBaseModel::getThisByTypeID($type, $id, $param);

        $data_type = strtolower(str_replace(['Sale', 'Rent'], '', $param));
        $parts = explode('\\', self::class);
        $short = array_pop($parts);
        switch ($short) {
            case "AppUser":
                $tittle = $data->first_name . ' ' . $data->last_name;
                break;
            case "Shows":
                $date = new \DateTime($data->showing_at);
                $tittle = 'Показ від ' . constant('TG_' . $data->client_type) . '; Час: ' . $date->format('H:i - d/m');
                break;
            case "Objects":
            case "advertTG":
            case "Adverts":
                $price = 'price_' . $data->currency;
                $tittle = self::$translate_data[$data->category] . ' ' . $data->rooms . 'кім. '
                    .  self::$translate_data[$data->type] . ' по вул.' . $data->street
                    . ($data->$price ? ', ' . $data->$price . $data->currency : '');
                break;
            case "request":
                $tittle = $data->search_name;
                break;
            case "favourite":
                $tittle = $data->list_name;
                break;
            case "company":
                $tittle = $data->name;
                break;
            default:{
                $tittle = "Empty tittle";
                break;
            }
        }
        return $tittle ?? "Empty tittle";
    }

//    abstract public static function getTittle(array $data);
    abstract public function getDescription();
    abstract public function getThumbUrl();
    abstract public function getTGCard(int $user_id, array $params);
    abstract public function render(): array;
    abstract public function getPreOutMessage($params = array()): string;
    abstract protected function getInlineData($params = array()): array;
    public static function getInlineArticleArr(array $data_arr, $params = array())
    {
        $articles = array();

        if(!count($data_arr)){
            return InlinequeryCommand::getInlineError('Нічого не знайдено');
        }

        foreach ($data_arr as $key => $data) {
            $card_builder = new (self::class)($data->toArray());
            $inlineQueryResult = $card_builder->getInlineData($params + ['key' => $key + 1]);

//            $inlineQueryResult = $this->$functionGetInlineData($data, $params + ['key' => $key + 1]);

            $inlineQueryResult["id"] = $key + 1;
            $inlineQueryResult["parse_mode"] = 'HTML';
            $articles[] = new InlineQueryResultArticle($inlineQueryResult);
        };

        return $articles;
    }
    protected function t($text): string { return Translator::t($text); }
    protected function pm($command, $page): string { return Translator::pm($command, $page); }

}