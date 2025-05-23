<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Utils/autoload.php'; // Автозагрузка наших классов

use App\Utils\Cache;
use App\Utils\CacheLoader;
use Weeek\Client as WeeekClient;

// Загрузка конфигурации
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

// Создание клиента Weeek API
$apiToken = getenv('WEEEK_API_TOKEN') ?: $config['auth']['weeek_api_token'];
$weeekClient = new WeeekClient($apiToken);

// Инициализация кеша и загрузка данных
$cache = Cache::getInstance();
$cacheLoader = new CacheLoader($weeekClient, $config);

// Включаем логирование
ini_set('error_log', '/tmp/weeek_cache_debug.log');
ini_set('log_errors', 1);
error_log("=== Начинаем обновление кеша досок ===");

// Очищаем кеш досок для теста
$cache->delete('boards');
error_log("Кеш досок очищен");

// Обновляем кеш
$result = $cacheLoader->loadBoards();

// Проверяем результат
if ($result) {
    $boards = $cache->get('boards', ['data' => []]);
    $boardCount = count($boards['data']);
    
    echo "Кеш досок успешно обновлен\n";
    echo "Найдено досок: {$boardCount}\n";
    echo "Обновлено: " . date('Y-m-d H:i:s', $boards['lastUpdated']) . "\n";
    
    // Показываем первые 5 досок
    echo "\nПервые доски в кеше:\n";
    $i = 0;
    foreach ($boards['data'] as $board) {
        if ($i >= 5) break;
        echo "- ID: {$board['id']}, Название: {$board['title']}, Проект: {$board['project_id']}\n";
        $i++;
    }
    
    echo "\nЛоги сохранены в /tmp/weeek_cache_debug.log\n";
} else {
    echo "Ошибка обновления кеша досок\n";
    echo "Проверьте логи в /tmp/weeek_cache_debug.log\n";
} 