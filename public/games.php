<?php
// 1) Завантажуємо Bootstrap Web
require __DIR__ . '/../app/bootstrap_games.php';

exit;
// 2) Обробляємо HTTP Request через Phalcon MVC
/** @var \Phalcon\Mvc\Application $application */
$response = $application->handle('/Games/PaintIO/public');
echo $response->getContent();
