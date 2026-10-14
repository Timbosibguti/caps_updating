<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Загрузка переменных окружения из одного файла .env
$dotenv = Dotenv::createImmutable(__DIR__, '.env');
$dotenv->load();

$dotenv->required([
    'DOMAIN',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'GOOGLE_REDIRECT_URL',
]);

require_once __DIR__ . '/config/Config.php';
use Config\Config;

// Инициализация Google OAuth клиента с переменными из .env
$client = new Google_Client();
$client->setClientId(Config::get('GOOGLE_CLIENT_ID'));
$client->setClientSecret(Config::get('GOOGLE_CLIENT_SECRET'));
$client->setRedirectUri(Config::get('GOOGLE_REDIRECT_URL'));
$client->addScope('email');
$client->addScope('profile');

if (!defined('SESSION_ID')) {
    define('SESSION_ID', 'my_project');
}

$GOOGLE_CLIENT_ID = Config::get('GOOGLE_CLIENT_ID');
$GOOGLE_CLIENT_SECRET = Config::get('GOOGLE_CLIENT_SECRET');
$GOOGLE_REDIRECT_URL = Config::get('GOOGLE_REDIRECT_URL');

/**
 * Проверка авторизации пользователя и редирект на Google OAuth, если не авторизован
 */
if (!isset($_SESSION[SESSION_ID]['logged']) && !isset($_GET['code'])) {
    $url = "https://accounts.google.com/o/oauth2/auth";

    $params = array(
        "response_type" => "code",
        "client_id" => $GOOGLE_CLIENT_ID,
        "redirect_uri" => $GOOGLE_REDIRECT_URL,
        "scope" => "https://www.googleapis.com/auth/userinfo.email",
        "prompt" => "select_account consent",
        "access_type" => "offline",
    );

    $request_to = $url . '?' . http_build_query($params);

    header("Location: " . $request_to);
    exit;
}

/**
 * Обработка ответа от Google OAuth, получение access_token и id_token, создание пользователя в БД
 */
if (isset($_GET['code']) && !isset($_SESSION[SESSION_ID]['logged'])) {
    $params = array(
        'client_id' => $GOOGLE_CLIENT_ID,
        'client_secret' => $GOOGLE_CLIENT_SECRET,
        'redirect_uri' => $GOOGLE_REDIRECT_URL,
        'grant_type' => 'authorization_code',
        'code' => $_GET['code']
    );

    $ch = curl_init('https://accounts.google.com/o/oauth2/token');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    $data = curl_exec($ch);
    $error = curl_error($ch);

    curl_close($ch);

    $data = json_decode($data, true);

    if (!empty($data['access_token'])) {
        $client = new Google_Client(['client_id' => $GOOGLE_CLIENT_ID]);
        $client->addScope('profile');
        $payload = $client->verifyIdToken($data['id_token']);

        if ($payload) {
            $userid = $payload['sub'];
            $email = $payload['email'];

            // Инициализация базы данных перед работой с пользователями
            require_once __DIR__ . "/db/DatabaseInitializer.php";
            $dbInitializer = new \DB\DatabaseInitializer();
            $dbInitializer->initialize();

            require_once __DIR__ . "/db/Users.php";

            $usersManager = new Users();
            $user = $usersManager->getUserByEmail($email);

            if (!$user) {
                $usersManager->addUserByEmail($email);
                $user = $usersManager->getUserByEmail($email);
            }

            if ($user && isset($user['login'])) {
                $_SESSION[SESSION_ID]['logged'] = $user['login'];
                $_SESSION[SESSION_ID]['user'] = $user;
            } else {
                // Если не удалось получить пользователя, перенаправляем на ошибку
                die('Ошибка авторизации: не удалось создать пользователя');
            }

            header("Location: /");
            exit;
        }
    }
}
