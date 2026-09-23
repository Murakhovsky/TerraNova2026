<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Infrastructure\Platform\Notification\BuiltinNotificationTemplateRepository;
use Infrastructure\Platform\Notification\SimpleNotificationTemplateRenderer;
use Platform\Notification\Model\Channel;

function expectPlatformNotificationRuntime(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$templates=new BuiltinNotificationTemplateRepository();
$template=$templates->find(BuiltinNotificationTemplateRepository::GROWTH_OUTBOUND_MESSAGE,Channel::EMAIL,'uk');
expectPlatformNotificationRuntime($template!==null,'Growth outbound email built-in template is missing.');
expectPlatformNotificationRuntime($template->locale==='uk','Built-in notification template must preserve requested locale.');
expectPlatformNotificationRuntime(
    $templates->find(BuiltinNotificationTemplateRepository::GROWTH_OUTBOUND_MESSAGE,Channel::TELEGRAM,'uk')===null,
    'Growth outbound template must not masquerade as Telegram template.'
);

$rendered=(new SimpleNotificationTemplateRenderer())->render($template,[
    'subject'=>'Short diagnostic',
    'body'=>'Would a 20-minute diagnostic be useful?',
]);
expectPlatformNotificationRuntime($rendered->subject==='Short diagnostic','Notification subject rendering failed.');
expectPlatformNotificationRuntime($rendered->body==='Would a 20-minute diagnostic be useful?','Notification body rendering failed.');

echo "Platform Notification runtime contracts passed.\n";
