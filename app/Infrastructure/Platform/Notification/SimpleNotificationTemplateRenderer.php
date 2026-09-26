<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Notification;

use Platform\Notification\Contract\TemplateRendererInterface;
use Platform\Notification\Model\RenderedNotification;
use Platform\Notification\Model\Template;

final class SimpleNotificationTemplateRenderer implements TemplateRendererInterface
{
    public function render(Template $template,array $variables):RenderedNotification
    {
        $replace=static function(string $value)use($variables):string{
            return (string)preg_replace_callback('/\{\{([a-zA-Z0-9_.-]+)\}\}/',static function(array $match)use($variables):string{
                $value=$variables[$match[1]]??'';
                return is_scalar($value)||$value===null?(string)$value:'';
            },$value);
        };

        $body=$replace($template->body);
        $subject=$template->subject===null?null:trim($replace($template->subject));
        return new RenderedNotification($subject===''?null:$subject,$body);
    }
}
