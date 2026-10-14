<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Явная загрузка .env до вызовов Config::get()
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

require_once __DIR__ . '/security_log.php';
require_once __DIR__ . '/config/Config.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/db/Database.php';
require_once __DIR__ . '/db/DatabaseInitializer.php';
require_once __DIR__ . '/db/Repository/RotationSummaryRepository.php';
require_once __DIR__ . '/services/DataProcessor.php';
require_once __DIR__ . '/services/NotificationService.php';


use Config\Config;
use Config\Constants;
use DB\Database;
use DB\DatabaseInitializer;
use DB\Repository\RotationSummaryRepository;
use Services\DataProcessor;
use Services\NotificationService;

// Функция для логирования в fetch_data.log
function log_fetch($msg) {
    $log = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $msg);
    file_put_contents(__DIR__ . '/fetch_data.log', $log, FILE_APPEND);
}

/**
 * Парсит pql вида:
 * hourOfDay:+0300 >= '14:00' AND hourOfDay:+0300 <= '23:30'
 */
function parseWorkScheduleFromPql(?string $pql): ?array {
    if (!is_string($pql) || $pql === '') {
        return null;
    }

    $pattern = '/hourOfDay:([+-]\d{4})\s*[><=]+\s*[\'"](\d{2}:\d{2})[\'"].*hourOfDay:\1\s*[><=]+\s*[\'"](\d{2}:\d{2})[\'"]/i';
    if (!preg_match($pattern, $pql, $matches)) {
        return null;
    }

    return [
        'offset' => $matches[1],
        'start' => $matches[2],
        'end' => $matches[3]
    ];
}

/**
 * Проверяет, открыто ли окно работы партнера в текущий UTC момент.
 */
function isPartnerOpenNow(?string $pql, \DateTime $utcNow): bool {
    $schedule = parseWorkScheduleFromPql($pql);
    if ($schedule === null) {
        // Нет часового окна в pql — считаем всегда открытым (не вычитаем емкость)
        return true;
    }

    $offset = $schedule['offset'];
    $tz = new \DateTimeZone(substr($offset, 0, 3) . ':' . substr($offset, 3, 2));

    $localNow = clone $utcNow;
    $localNow->setTimezone($tz);
    $currentMinutes = ((int)$localNow->format('H')) * 60 + ((int)$localNow->format('i'));

    [$startHour, $startMinute] = array_map('intval', explode(':', $schedule['start']));
    [$endHour, $endMinute] = array_map('intval', explode(':', $schedule['end']));
    $startMinutes = $startHour * 60 + $startMinute;
    $endMinutes = $endHour * 60 + $endMinute;

    if ($startMinutes <= $endMinutes) {
        return $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;
    }

    // Окно через полночь, например 22:00-06:00
    return $currentMinutes >= $startMinutes || $currentMinutes <= $endMinutes;
}

function formatPartnerCountRu(int $count): string {
    $mod10 = $count % 10;
    $mod100 = $count % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return "{$count} партнера";
    }
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
        return "{$count} партнеров";
    }
    return "{$count} партнеров";
}

function loadCloseCenterNotificationState(string $filePath): array {
    if (!file_exists($filePath)) {
        return [];
    }
    $raw = @file_get_contents($filePath);
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveCloseCenterNotificationState(string $filePath, array $state): void {
    $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        @file_put_contents($filePath, $encoded);
    }
}

function buildCloseCenterSignature(int $closeCenterDeduction, array $closedPartnerKeys): string {
    sort($closedPartnerKeys, SORT_STRING);
    return $closeCenterDeduction . '|' . implode(',', $closedPartnerKeys);
}

// Проверяем наличие расширений PostgreSQL
if (!extension_loaded('pgsql')) {
    die('Ошибка: Расширение PostgreSQL (pgsql) не установлено. Установите php-pgsql.');
}

if (!extension_loaded('pdo_pgsql')) {
    die('Ошибка: Расширение PDO PostgreSQL (pdo_pgsql) не установлено. Установите php-pdo-pgsql.');
}

// Инициализация базы данных
$dbInitializer = new DatabaseInitializer();
$dbInitializer->initialize();

$apiToken = Config::get('API_TOKEN');
$apiUrl = Config::get('API_URL');
$slackWebhookUrl = Config::get('SLACK_WEBHOOK_URL');
$slackChannel = Config::get('SLACK_CHANNEL', '#general');
$slackUsername = Config::get('SLACK_USERNAME', 'Notifier');

// Получение данных из API
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . $apiToken,
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
if (curl_errno($ch)) {
    die('Ошибка cURL: ' . curl_error($ch));
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode !== Constants::HTTP_OK) {
    die("Ошибка запроса: HTTP $httpCode\nОтвет: " . substr($response, 0, Constants::LOG_RESPONSE_LIMIT));
}
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE || $data === null) {
    log_security_event("Ошибка декодирования JSON: " . json_last_error_msg() . " | Ответ: " . substr($response, 0, 200) . " | IP: {$_SERVER['REMOTE_ADDR']}");
    die('Ошибка обработки данных. Попробуйте позже.');
}

// Кэширование API-ответа на 5 минут
$cacheDir = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/api_response.json';

// Создаем директорию cache если она не существует
if (!is_dir($cacheDir)) {
    if (!@mkdir($cacheDir, 0755, true)) {
        // Если не удалось создать директорию, используем временную директорию
        $cacheDir = sys_get_temp_dir();
        $cacheFile = $cacheDir . '/api_response.json';
    }
}

$cacheTtl = Constants::CACHE_API_TTL_SECONDS; // 5 минут
$data = null;
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $cacheContent = file_get_contents($cacheFile);
    if ($cacheContent !== false) {
        $data = json_decode($cacheContent, true);
    }
}
if (!$data) {
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $apiToken,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        die('Ошибка cURL: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== Constants::HTTP_OK) {
        die("Ошибка запроса: HTTP $httpCode\nОтвет: " . substr($response, 0, Constants::LOG_RESPONSE_LIMIT));
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || $data === null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        log_security_event("Ошибка декодирования JSON: " . json_last_error_msg() . " | Ответ: " . substr($response, 0, Constants::LOG_RESPONSE_LIMIT) . " | IP: $ip");
        die('Ошибка обработки данных. Попробуйте позже.');
    }

    // Сохраняем в кэш с проверкой ошибок
    $cacheData = json_encode($data);
    if ($cacheData !== false) {
        if (@file_put_contents($cacheFile, $cacheData) === false) {
            // Если не удалось записать в кэш, продолжаем работу без кэширования
            error_log("Не удалось записать в кэш: $cacheFile");
        }
    }
}

// Инициализация сервисов
$notificationService = new NotificationService($slackWebhookUrl, $slackChannel, $slackUsername);

// Обработка данных и уведомления
$result = DataProcessor::processTree($data, function($capName, $percentage) {
    // Уведомления теперь обрабатываются в основном цикле ниже, этот callback не нужен
});

// Получаем список всех cap_name для batch запроса порогов
$allCapNames = [];
foreach ($result as $country => $funnels) {
    foreach ($funnels as $funnel => $values) {
        $allCapNames[] = "$country | $funnel";
    }
}

// Работа с БД для rotation_summary и уведомления
$rotationRepository = new RotationSummaryRepository();

$thresholds = Constants::NOTIFICATION_THRESHOLDS;

// Получаем текущую дату по UTC для группировки и сохранения в БД
$utcNow = new \DateTime('now', new \DateTimeZone('UTC'));
$dbYear = (int)$utcNow->format('Y');
$dbMonth = (int)$utcNow->format('m');
$dbDay = (int)$utcNow->format('d');
$closeCenterStateFile = __DIR__ . '/cache/close_center_notifications.json';
$closeCenterNotificationState = loadCloseCenterNotificationState($closeCenterStateFile);

// Группируем ноды по country + funnel (все под текущей UTC датой, без учета updatedAt)
$groupedData = [];

foreach ($result as $country => $funnels) {
    foreach ($funnels as $funnel => $funnelData) {
        if (!is_array($funnelData) || !isset($funnelData['nodes'])) {
            continue; // Пропускаем некорректные данные
        }

        // Получаем UUID страны (родителя)
        $countryUuid = $funnelData['countryUuid'] ?? '';
        if (!is_string($countryUuid) || $countryUuid === '') {
            // Fallback: если нет UUID страны, используем hash
            $countryUuid = 'geo:' . hash('sha256', $country . '|' . $funnel);
        }

        // Создаем одну группу для (country, funnel) под текущей UTC датой
        if (!isset($groupedData[$country][$funnel])) {
            $groupedData[$country][$funnel] = [
                'countryUuid' => $countryUuid,
                'year' => $dbYear,
                'month' => $dbMonth,
                'day' => $dbDay,
                'nodes' => [],
                'totalCapCount' => 0,
                'totalCapAmount' => 0
            ];
        }

        // Добавляем все ноды в одну группу (независимо от updatedAt, API отдает по UTC)
        foreach ($funnelData['nodes'] as $nodeUuid => $nodeData) {
            if (!is_array($nodeData)) {
                continue;
            }

            $capCount = (int)($nodeData['capCount'] ?? 0);
            $capAmount = (int)($nodeData['capAmount'] ?? 0);

            $groupedData[$country][$funnel]['nodes'][$nodeUuid] = [
                'capCount' => $capCount,
                'capAmount' => $capAmount,
                'partnerUuid' => $nodeData['partnerUuid'] ?? null,
                'partnerName' => $nodeData['partnerName'] ?? null,
                'partnerPql' => $nodeData['partnerPql'] ?? null
            ];
            $groupedData[$country][$funnel]['totalCapCount'] += $capCount;
            $groupedData[$country][$funnel]['totalCapAmount'] += $capAmount;
        }
    }
}

// Обрабатываем сгруппированные данные (группировка по country + funnel, дата - текущая UTC)
foreach ($groupedData as $country => $funnels) {
    foreach ($funnels as $funnel => $dateData) {
        $countryUuid = $dateData['countryUuid'];
        $totalCapCount = $dateData['totalCapCount'];
        $totalCapAmount = $dateData['totalCapAmount'];
        $nodes = $dateData['nodes'];

        // Используем UUID страны для идентификации записи
        $uuid = $countryUuid;

        // Получаем существующую запись из БД для инкремента (по текущей UTC дате)
        $existingRecord = $rotationRepository->getRecordByCountryFunnelDate($country, $funnel, $dbDay, $dbMonth, $dbYear);
        $existingNodesData = $existingRecord && isset($existingRecord['nodes_data']) ? $existingRecord['nodes_data'] : [];
        $existingCapCount = $existingRecord ? (int)$existingRecord['capcount'] : 0;
        $existingCapAmount = $existingRecord ? (int)$existingRecord['capamount'] : 0;

        // ИНКРЕМЕНТ ЛОГИКА: объединяем данные из БД и API
        $finalNodesData = $existingNodesData; // Начинаем с данных из БД

        foreach ($nodes as $nodeUuid => $nodeData) {
            $apiCapCount = (int)$nodeData['capCount'];
            $apiCapAmount = (int)$nodeData['capAmount'];
            $apiPartnerUuid = $nodeData['partnerUuid'] ?? null;
            $apiPartnerName = $nodeData['partnerName'] ?? null;
            $apiPartnerPql = $nodeData['partnerPql'] ?? null;

            if (isset($finalNodesData[$nodeUuid])) {
                $existingNodeCapCount = (int)($finalNodesData[$nodeUuid]['capCount'] ?? 0);
                // Суточный UTC-сброс в iRev: суммарно 0 заполненности при живых лимитах — не затираем capCount нулями из API
                $utcDayResetZeroFill = ($totalCapCount === 0 && $totalCapAmount > 0);
                if ($utcDayResetZeroFill && $apiCapCount === 0 && $existingNodeCapCount > 0) {
                    $finalNodesData[$nodeUuid]['capCount'] = $existingNodeCapCount;
                } else {
                    $finalNodesData[$nodeUuid]['capCount'] = $apiCapCount;
                }
                // capAmount — всегда из API (лимит после сброса/правок)
                $finalNodesData[$nodeUuid]['capAmount'] = $apiCapAmount;
                if ($apiPartnerUuid !== null) {
                    $finalNodesData[$nodeUuid]['partnerUuid'] = $apiPartnerUuid;
                }
                if ($apiPartnerName !== null) {
                    $finalNodesData[$nodeUuid]['partnerName'] = $apiPartnerName;
                }
                if ($apiPartnerPql !== null) {
                    $finalNodesData[$nodeUuid]['partnerPql'] = $apiPartnerPql;
                }
            } else {
                // Новый UUID - добавляем ноду (инкремент)
                $finalNodesData[$nodeUuid] = [
                    'capCount' => $apiCapCount,
                    'capAmount' => $apiCapAmount,
                    'partnerUuid' => $apiPartnerUuid,
                    'partnerName' => $apiPartnerName,
                    'partnerPql' => $apiPartnerPql
                ];
            }
        }

        // Убираем ноды, которых уже нет в дереве API (не копим «хвосты»), только если в ответе есть ноды — иначе не трогаем при пустом ответе
        if ($nodes !== []) {
            foreach (array_keys($finalNodesData) as $uuid) {
                if (!isset($nodes[$uuid])) {
                    unset($finalNodesData[$uuid]);
                }
            }
        }

        // Суммируем финальные значения из объединенных данных
        $finalCapCount = 0;
        $finalCapAmount = 0;
        foreach ($finalNodesData as $nodeData) {
            $finalCapCount += (int)$nodeData['capCount'];
            $finalCapAmount += (int)$nodeData['capAmount'];
        }

        // Уменьшаем общую емкость гео на остаток не запушенных лидов у партнеров с закрытым КЦ:
        // остаток = capAmount - capCount
        $capAmountBeforePartnerCloseAdjust = $finalCapAmount;
        $closeCenterDeduction = 0;
        $closedPartners = [];
        foreach ($finalNodesData as $nodeUuid => $nodeData) {
            $partnerPql = $nodeData['partnerPql'] ?? null;
            if (isPartnerOpenNow($partnerPql, $utcNow)) {
                continue;
            }

            $nodeCapAmount = (int)($nodeData['capAmount'] ?? 0);
            $nodeCapCount = (int)($nodeData['capCount'] ?? 0);
            $remainingUnpushed = max(0, $nodeCapAmount - $nodeCapCount);
            if ($remainingUnpushed <= 0) {
                continue;
            }

            $closeCenterDeduction += $remainingUnpushed;
            $partnerKey = $nodeData['partnerUuid'] ?? $nodeUuid;
            $closedPartners[$partnerKey] = true;
        }

        if ($closeCenterDeduction > 0) {
            $finalCapAmount = max(0, $finalCapAmount - $closeCenterDeduction);
        }

        // capAmount обновляем всегда (лимит может меняться)
        if ($totalCapAmount == 0 && $existingCapAmount > 0) {
            $finalCapAmount = $existingCapAmount;
        }

        // Считаем дельту по capAmount только при увеличении лимита
        // updatedAmount накапливается как сумма всех положительных приростов за день
        $updatedAmount = null;
        if ($existingCapAmount !== null && $finalCapAmount > $existingCapAmount) {
            $updatedAmount = $finalCapAmount - $existingCapAmount;
        }

        $sentAddedCapacitySlack = ($updatedAmount !== null && $updatedAmount > 0);

        // Slack: добавленная ёмкость по гео (дельта capAmount за этот запуск)
        if ($sentAddedCapacitySlack) {
            $capName = "$country | $funnel";
            $addedTo = $finalCapAmount;
            $addedFrom = $existingCapAmount ?? 0;
            $message = "$capName | Добавлена ёмкость: +{$updatedAmount} (было {$addedFrom} → стало {$addedTo})";
            $vietnamMessage = "$capName | Capacity added: +{$updatedAmount} (was {$addedFrom} → now {$addedTo})";
            $notificationService->sendSlackNotification($message, $vietnamMessage);
        }

        $notifiedCloseCenterReduction = false;
        // Slack: уменьшение из-за закрытого КЦ партнеров
        if ($closeCenterDeduction > 0) {
            $capName = "$country | $funnel";
            $partnerCount = count($closedPartners);
            $partnerCountRu = formatPartnerCountRu($partnerCount);
            $ccStateKey = "{$dbYear}-{$dbMonth}-{$dbDay}|{$country}|{$funnel}";
            $closedPartnerKeys = array_keys($closedPartners);
            $ccStateSignature = buildCloseCenterSignature($closeCenterDeduction, $closedPartnerKeys);
            $lastCcStateSignature = $closeCenterNotificationState[$ccStateKey] ?? null;
            if (!$sentAddedCapacitySlack && $lastCcStateSignature !== $ccStateSignature) {
                $message = "$capName | Уменьшена ёмкость: −{$closeCenterDeduction} (было {$capAmountBeforePartnerCloseAdjust} → стало {$finalCapAmount}). Причина: закрылся КЦ у {$partnerCountRu}";
                $vietnamMessage = "$capName | Capacity reduced: −{$closeCenterDeduction} (was {$capAmountBeforePartnerCloseAdjust} → now {$finalCapAmount}). Reason: call center closed for {$partnerCount} partner(s)";
                $notificationService->sendSlackNotification($message, $vietnamMessage);
                $closeCenterNotificationState[$ccStateKey] = $ccStateSignature;
                $notifiedCloseCenterReduction = true;
            }
        } else {
            // Если КЦ снова открыты и вычета нет, очищаем состояние дедупа.
            $ccStateKey = "{$dbYear}-{$dbMonth}-{$dbDay}|{$country}|{$funnel}";
            unset($closeCenterNotificationState[$ccStateKey]);
        }

        // Slack: уменьшение ёмкости (лимит в API ниже, чем был сохранён в БД)
        if (!$notifiedCloseCenterReduction && $existingCapAmount > 0 && $finalCapAmount < $existingCapAmount) {
            $decreasedBy = $existingCapAmount - $finalCapAmount;
            $capName = "$country | $funnel";
            $message = "$capName | Уменьшена ёмкость: −{$decreasedBy} (было {$existingCapAmount} → стало {$finalCapAmount})";
            $vietnamMessage = "$capName | Capacity reduced: −{$decreasedBy} (was {$existingCapAmount} → now {$finalCapAmount})";
            $notificationService->sendSlackNotification($message, $vietnamMessage);
        }

        $percentage = $finalCapAmount > 0 ? (($finalCapAmount - $finalCapCount) / $finalCapAmount) * Constants::PERCENTAGE_MULTIPLIER : 0;
        $percentage = round($percentage, Constants::PERCENTAGE_DECIMALS);

        // Получаем текущий last_notified_threshold из БД (по текущей UTC дате)
        $lastNotified = $rotationRepository->getLastNotifiedThreshold($country, $funnel, $dbDay, $dbMonth, $dbYear, $uuid);
        $notifiedThisRun = false;
        $currentThreshold = null;

        // Уведомления по порогам: за один запуск не больше одного сообщения.
        // Берём самый «жёсткий» порог T из списка, для которого percentage <= T,
        // и шлём только если ушли ниже уже зафиксированного lastNotified.
        if ($finalCapCount > 0) {
            $activeThreshold = null;
            foreach ($thresholds as $threshold) {
                if ($percentage <= $threshold) {
                    $activeThreshold = $activeThreshold === null ? $threshold : min($activeThreshold, $threshold);
                }
            }
            if ($activeThreshold !== null && ($lastNotified === null || $activeThreshold < $lastNotified)) {
                $capName = "$country | $funnel";
                $message = "$capName | Остаток: $percentage%";
                $vietnamMessage = "$capName | Remaining: $percentage%";
                $notificationService->sendSlackNotification($message, $vietnamMessage);
                $rotationRepository->setLastNotifiedThreshold($country, $funnel, $dbDay, $dbMonth, $dbYear, $uuid, $activeThreshold);
                $notifiedThisRun = true;
                $currentThreshold = $activeThreshold;
            }

            // Проверяем перелив капы (>= 105%)
            if ($percentage >= Constants::OVERFLOW_THRESHOLD) {
                $capName = "$country | $funnel";
                $message = "*$capName | Перелив: >5%*";
                $vietnamMessage = "*$capName | Overflow: >5%*";
                $notificationService->sendSlackNotification($message, $vietnamMessage);
            }
        }

        // Если процент снова выше 50% — сбрасываем поле
        if ($percentage > 50 && $lastNotified !== null) {
            $rotationRepository->setLastNotifiedThreshold($country, $funnel, $dbDay, $dbMonth, $dbYear, $uuid, null);
        }

        // Сохраняем данные с текущей UTC датой (не из updatedAt)
        $entity = [
            'country' => $country,
            'funnel' => $funnel,
            'capcount' => $finalCapCount,
            'capamount' => $finalCapAmount,
            'day' => $dbDay,
            'month' => $dbMonth,
            'year' => $dbYear,
            'uuid' => $uuid,
            'last_notified_threshold' => ($notifiedThisRun ? $currentThreshold : $lastNotified),
            'updatedAmount' => $updatedAmount,
            'nodes_data' => $finalNodesData
        ];
        $rotationRepository->save($entity);
    }
}

// Удаление записей старее 3х месяцев
$rotationRepository->deleteOldRecords(Constants::DATA_RETENTION_MONTHS);
saveCloseCenterNotificationState($closeCenterStateFile, $closeCenterNotificationState);

// Выводим красивое сообщение об успешном обновлении
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Данные обновлены</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            background-color: #f5f5f5;
            margin: 0;
            padding: 50px 20px;
        }
        .message {
            background-color: #fff;
            border-radius: 10px;
            padding: 40px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            margin: 0 auto;
        }
        .success-icon {
            font-size: 48px;
            color: #28a745;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        p {
            color: #666;
            font-size: 16px;
            margin: 0;
        }
        .back-link {
            margin-top: 30px;
        }
        .back-link a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="message">
    <div class="success-icon">✅</div>
    <h1>Данные обновлены</h1>
    <p>Приятной работы!</p>
    <div class="back-link">
        <a href="/">← Посмотреть данные</a>
    </div>
</div>
</body>
</html>