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
ini_set('error_log', '/tmp/weeek_create_board.log');
ini_set('log_errors', 1);
error_log("=== Начинаем тест создания доски ===");

// Получаем счетчик досок до создания
$boardsBeforeCreate = $cache->get('boards', ['data' => []]);
$countBeforeCreate = count($boardsBeforeCreate['data']);
echo "Количество досок в кеше до создания: {$countBeforeCreate}\n";

// Создаем новую доску
try {
    $boardName = "Тест " . date('Y-m-d H:i:s');
    $projectId = $config['default_project_id'];
    
    echo "Создаем доску '{$boardName}' в проекте {$projectId}...\n";
    
    $boardData = [
        'name' => $boardName,
        'projectId' => $projectId,
    ];
    
    $response = $weeekClient->taskManager->boards->create($boardData);
    
    // Обновляем кеш досок
    echo "Обновляем кеш досок...\n";
    $cacheLoader->reloadBoards();
    
    // Проверяем кеш после создания
    $boardsAfterCreate = $cache->get('boards', ['data' => []]);
    $countAfterCreate = count($boardsAfterCreate['data']);
    
    echo "Количество досок в кеше после создания: {$countAfterCreate}\n";
    
    if ($countAfterCreate > $countBeforeCreate) {
        echo "Кеш успешно обновлен! Добавлено " . ($countAfterCreate - $countBeforeCreate) . " досок.\n";
    } else {
        echo "Предупреждение: кеш не изменился после создания доски.\n";
    }
    
    // Показываем информацию о созданной доске
    echo "\nДоска успешно создана!\n";
    echo "Идентификатор доски: {$response->board->id}\n";
    echo "Название: {$response->board->name}\n";
    echo "Идентификатор проекта: {$response->board->projectId}\n";
    
} catch (Exception $e) {
    echo "Ошибка при создании доски: " . $e->getMessage() . "\n";
    error_log("Ошибка при создании доски: " . $e->getMessage());
    error_log("Трассировка: " . $e->getTraceAsString());
}

echo "\nЛоги сохранены в /tmp/weeek_create_board.log\n";
