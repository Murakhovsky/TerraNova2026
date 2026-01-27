## Telegram Bot Documentation

### Set commands to bot commands list 

If you want to set new command for bot, add `name - description` into this list and copy all text. Then open @BotFather in you telegram as admin, select command `/setcommands` and paste current text.  

```php
menu - Головне меню
hidekb - Закінчити команду
```
```php
menu - Головне меню
submit - подати оголошення
searchobjects - знайти об'єкт в базі
searchadverts - знайти оголошення
objectlist - список моїх оголошень (в розробці)
searchlist - збережені пошуки (в розробці)
setselection - створити підбірку (в розробці)
selectedlist - список підбірок
need - подати попит (в розробці)
weather - погода у Львові
survey - опитування (в розробці)
keyboard - клавіатура (в розробці)
inlinekeyboard - потокова клавіатура (в розробці)
hidekb - закрити клавіатуру
```
### Підпер костилем, після оновлення вкласти на місце!!! 
\longman\telegram-bot\src\Telegram.php line387
```
if ($auth === Command::AUTH_USER && $command_obj instanceof UserCommand) {
```
замінити на 
```
if ($auth === Command::AUTH_USER && ($command_obj instanceof UserCommand || $command_obj instanceof MyUserCommand )) {
```
додавши на початку файлу
```
use Longman\TelegramBot\Commands\MyUserCommand;
```