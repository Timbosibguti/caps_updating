<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/security_log.php';
require_once __DIR__ . '/config/Config.php';
require_once __DIR__ . '/db/Database.php';
require_once __DIR__ . '/db/DatabaseInitializer.php';
require_once __DIR__ . '/db/Repository/RotationSummaryRepository.php';
require_once __DIR__ . '/services/AuthService.php';


use Config\Config;
use DB\Database;
use DB\DatabaseInitializer;
use DB\Repository\RotationSummaryRepository;
use Config\Constants;
use Services\AuthService;
// --- Security headers ---
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline';");
header("Strict-Transport-Security: max-age=" . Constants::HSTS_MAX_AGE . "; includeSubDomains; preload");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");

session_start();
require __DIR__ . '/auth.php';

// Централизованная проверка авторизации
AuthService::requireAuth();

/**
 * Проверка авторизации пользователя
 */
if (!defined('SESSION_ID')) {
    define('SESSION_ID', 'my_project');
}
if (!isset($_SESSION[SESSION_ID]['logged'])) {
    exit;
}

// Инициализация базы данных после успешной авторизации
$dbInitializer = new DatabaseInitializer();
$dbInitializer->initialize();

// CSRF: Генерация токена, если не существует
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(Constants::CSRF_TOKEN_LENGTH));
}
$csrf_token = $_SESSION['csrf_token'];

// Получаем дату из GET, либо ставим сегодня
$date = $_GET['date'] ?? date('Y-m-d');

// Для безопасности, проверим формат даты (простейшая проверка)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die("Некорректный формат даты");
}

// CSRF: Проверка токена при отправке формы
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['date']) && isset($_GET['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die('CSRF token mismatch');
    }
}

// Разбиваем дату на год, месяц, день
list($year, $month, $day) = explode('-', $date);

// После разбиения даты на $year, $month, $day
if (!checkdate((int)$month, (int)$day, (int)$year)) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    log_security_event("Некорректная дата: $date, IP: $ip");
    die("Некорректная дата");
}

// Rate limiting: не более RATE_LIMIT_REQUESTS запросов в RATE_LIMIT_WINDOW секунд на пользователя
if (!isset($_SESSION['rate_limit'])) {
    $_SESSION['rate_limit'] = [];
}
$now = time();
$_SESSION['rate_limit'] = array_filter(
    $_SESSION['rate_limit'],
    fn($timestamp) => $timestamp > $now - Constants::RATE_LIMIT_WINDOW // только за последний RATE_LIMIT_WINDOW
);
if (count($_SESSION['rate_limit']) >= Constants::RATE_LIMIT_REQUESTS) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    log_security_event("Rate limit exceeded: IP $ip");
    die('Слишком много запросов. Попробуйте позже.');
}
$_SESSION['rate_limit'][] = $now;

// Получение данных через Repository
try {
    $rotationRepository = new RotationSummaryRepository();
    $data = $rotationRepository->findByDate((int)$year, (int)$month, (int)$day);
} catch (Exception $e) {
    // Не показываем детали ошибки пользователю
    die('Внутренняя ошибка сервера. Попробуйте позже.');
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="robots" content="noindex, nofollow">
    <title>Обзор остатков по актуальным капам</title>
    <style>
        body {
            text-align: center;
        }
        table {
            border-collapse: collapse;
            width: <?= Constants::TABLE_WIDTH_PERCENT ?>;
            margin: <?= Constants::TABLE_MARGIN ?>;
            overflow: auto;
            font-family: Arial;
        }
        th, td {
            border: <?= Constants::TABLE_BORDER ?>;
            padding: <?= Constants::TABLE_PADDING ?>;
            text-align: left;
        }
        th {
            background-color: <?= Constants::TABLE_HEADER_BG ?>;
            color: #fff;
        }
        @media(max-width: <?= Constants::MOBILE_BREAKPOINT ?>px){
            th, td {
                font-size: <?= Constants::MOBILE_FONT_SIZE ?>;
            }
        }
        form {
            text-align: center;
            margin-bottom: <?= Constants::FORM_MARGIN_BOTTOM ?>;
        }
    </style>
</head>
<body>
    <h2 style="text-align:center;">Обзор остатков по актуальным капам</h2>
    
    <form method="GET">
        <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" required>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <button type="submit">Показать</button>
    </form>
    
    <table>
        <thead>
            <tr>
                <th>Капы</th>
                <th>Остаток</th>
                <th>Номинал</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data)): ?>
                <tr><td colspan="3" style="text-align:center;">Нет данных за выбранную дату</td></tr>
            <?php else: ?>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['country'] . ' | ' . $row['funnel']) ?></td>
                        <td><?= (int)($row['capamount'] - $row['capcount']) ?></td>
                        <td><?= (int)$row['capamount'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>

<?php
if (isset($db)) {
    $db->close();
}
?>
