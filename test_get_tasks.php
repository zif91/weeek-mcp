<?php
require_once __DIR__ . '/vendor/autoload.php';

use WeeekMcp\Utils\Cache;
use WeeekMcp\Utils\CacheLoader;
use Weeek\Client as WeeekClient;
use WeeekMcp\Methods\TaskManager\Tasks;
use WeeekMcp\Methods\TaskManager;

// Загрузка конфигурации
$configFile = __DIR__ . '/config.json';
$config = json_decode(file_get_contents($configFile), true);

// Создание клиента Weeek API
$apiToken = $config['auth']['weeek_api_token'];
$weeekClient = new WeeekClient($apiToken);
$projectId = 1;

// Инициализация кеша
$cache = Cache::getInstance();
$cacheLoader = new CacheLoader($weeekClient, $config);

// Инициализация TaskManager
$taskManager = new TaskManager($weeekClient, $cacheLoader, $cache, $projectId);

// Инициализация менеджера задач
$tasksManager = new Tasks($weeekClient, $cacheLoader, $cache, $projectId, null);

// Загрузка данных
$cacheLoader->loadAllData();

// Функция для форматированного вывода задачи
function printTask($task, $level = 0) {
    $indent = str_repeat('  ', $level);
    echo $indent . '- ID: ' . $task['id'] . PHP_EOL;
    echo $indent . '  Название: ' . $task['title'] . PHP_EOL;
    
    if (!empty($task['description'])) {
        $desc = $task['description'];
        // Ограничиваем длину описания для вывода
        if (strlen($desc) > 100) {
            $desc = substr($desc, 0, 97) . '...';
        }
        echo $indent . '  Описание: ' . $desc . PHP_EOL;
    }
    
    echo $indent . '  Статус: ' . ($task['is_completed'] ? 'Выполнена' : 'Не выполнена') . PHP_EOL;
    echo $indent . '  Проект: ' . $task['project_id'] . PHP_EOL;
    
    if (isset($task['board_id']) && $task['board_id']) {
        echo $indent . '  Доска: ' . $task['board_id'] . PHP_EOL;
    }
    
    if (isset($task['board_column_id']) && $task['board_column_id']) {
        echo $indent . '  Колонка: ' . $task['board_column_id'] . PHP_EOL;
    }
    
    if (!empty($task['tags'])) {
        echo $indent . '  Теги: ';
        $tagNames = [];
        foreach ($task['tags'] as $tag) {
            $tagNames[] = $tag['title'] . ' (' . $tag['id'] . ')';
        }
        echo implode(', ', $tagNames) . PHP_EOL;
    }
    
    echo PHP_EOL;
}

// Получение задач для разных фильтров
echo "===== ТЕСТ ПОЛУЧЕНИЯ ЗАДАЧ =====\n\n";

// 1. Получение всех задач
echo "1. Получение всех задач проекта:\n";
$queryParams = ['projectId' => $projectId];
$tasks = $tasksManager->getAll($queryParams);
echo "Найдено задач: " . count($tasks) . "\n\n";

// Выборочный вывод первых 5 задач
$count = 0;
echo "Первые 5 задач:\n";
foreach ($tasks as $task) {
    printTask($task);
    $count++;
    if ($count >= 5) break;
}

// 2. Получение задач конкретной доски
echo "\n2. Получение задач конкретной доски:\n";
// Получаем список досок
$boardsResponse = $weeekClient->taskManager->boards->getAll(['projectId' => $projectId]);
$boardId = null;

// Выбираем первую доску из списка
if (isset($boardsResponse->boards) && count($boardsResponse->boards) > 0) {
    $boardId = $boardsResponse->boards[0]->id;
    $boardName = $boardsResponse->boards[0]->name;
    
    echo "Используем доску: $boardName (ID: $boardId)\n";
    $queryParams = [
        'projectId' => $projectId,
        'boardId' => $boardId
    ];
    
    $boardTasks = $tasksManager->getAll($queryParams);
    echo "Найдено задач на доске: " . count($boardTasks) . "\n\n";
    
    // Выборочный вывод первых 5 задач доски
    $count = 0;
    foreach ($boardTasks as $task) {
        printTask($task);
        $count++;
        if ($count >= 5) break;
    }
} else {
    echo "Доски не найдены для проекта $projectId\n";
}

// 3. Получение задач по колонке (если нашли доску ранее)
if ($boardId) {
    echo "\n3. Получение задач конкретной колонки:\n";
    
    // Получаем колонки доски
    try {
        // Получаем доступ к HTTP клиенту через рефлексию для прямого вызова API
        $reflection = new \ReflectionProperty($weeekClient->taskManager, 'http');
        $reflection->setAccessible(true);
        $http = $reflection->getValue($weeekClient->taskManager);
        
        // Выполняем запрос к API для получения колонок
        $columnsResponse = $http->get("/tm/boards/$boardId/columns");
        
        if (isset($columnsResponse->columns) && count($columnsResponse->columns) > 0) {
            $columnId = $columnsResponse->columns[0]->id;
            $columnName = $columnsResponse->columns[0]->name;
            
            echo "Используем колонку: $columnName (ID: $columnId)\n";
            $queryParams = [
                'projectId' => $projectId,
                'boardId' => $boardId,
                'boardColumnId' => $columnId
            ];
            
            $columnTasks = $tasksManager->getAll($queryParams);
            echo "Найдено задач в колонке: " . count($columnTasks) . "\n\n";
            
            // Выборочный вывод задач колонки
            foreach ($columnTasks as $task) {
                printTask($task);
            }
        } else {
            echo "Колонки не найдены для доски $boardId\n";
        }
    } catch (Exception $e) {
        echo "Ошибка при получении колонок: " . $e->getMessage() . "\n";
    }
}

// 4. Получение только выполненных задач
echo "\n4. Получение выполненных задач:\n";
$completedTasks = array_filter($tasks, function($task) {
    return $task['is_completed'] === true;
});
echo "Найдено выполненных задач: " . count($completedTasks) . "\n\n";

// Выборочный вывод первых 5 выполненных задач
$count = 0;
foreach ($completedTasks as $task) {
    printTask($task);
    $count++;
    if ($count >= 5) break;
}

// 5. Получение задач с тегами
echo "\n5. Получение задач с тегами:\n";
$tasksWithTags = array_filter($tasks, function($task) {
    return !empty($task['tags']);
});
echo "Найдено задач с тегами: " . count($tasksWithTags) . "\n\n";

// Выборочный вывод первых 5 задач с тегами
$count = 0;
foreach ($tasksWithTags as $task) {
    printTask($task);
    $count++;
    if ($count >= 5) break;
}

echo "\n===== ТЕСТ ЗАВЕРШЕН =====\n"; 