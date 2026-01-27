<?php
// 1) Завантажуємо Bootstrap Web
require __DIR__ . '/../app/bootstrap_tg.php';

exit;
// 2) Обробляємо HTTP Request через Phalcon MVC
/** @var \Phalcon\Mvc\Application $application */
$response = $application->handle('/tgAdmin/webhook');
echo $response->getContent();
