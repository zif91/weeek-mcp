<?php
require_once __DIR__ . '/vendor/autoload.php';

use WeeekMcp\Utils\Cache;
use WeeekMcp\Utils\CacheLoader;
use Weeek\Client as WeeekClient;
use WeeekMcp\Methods\TaskManager\Boards;

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

// Инициализация классов TaskManager
$boards = new Boards($weeekClient, $cacheLoader, $cache, $projectId);

// var_dump($boards->all());
// die();


$cacheLoader->loadAllData();

// Функция для создания задачи
function createTask($weeekClient, $cacheLoader, $taskData) {
    try {
        $response = $weeekClient->taskManager->tasks->create($taskData);
        echo "Задача создана успешно: ID=" . $response->task->id . ", Название=" . $response->task->title . PHP_EOL;
        return $response->task->id;
    } catch (Exception $e) {
        echo "Ошибка при создании задачи '" . $taskData['title'] . "': " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

// Загрузка задач из JSON файла
$tasksFile = __DIR__ . '/tasks.json';
if (file_exists($tasksFile)) {
    $tasksData = json_decode(file_get_contents($tasksFile), true);
   
    if (isset($tasksData['tasks']) && is_array($tasksData['tasks'])) {
        echo "Найдено " . count($tasksData['tasks']) . " задач в JSON файле." . PHP_EOL;
        
        // Сохраняем идентификаторы созданных задач для связывания родительских задач
        $createdTasksMap = [];
        
        // Создаем все задачи (сначала без родителей)
        foreach ($tasksData['tasks'] as $index => $task) {
            $boardId = isset($task['board_id']) ? $task['board_id'] : 2; // Используем доску "Задачи" по умолчанию
            
            // Если указано имя доски, пытаемся найти её ID в маппинге
            if (isset($task['board']) && isset($tasksData['boards'][$task['board']])) {
                $boardId = $tasksData['boards'][$task['board']];
            }
            
            // Подготовка тегов, если они указаны
            $tagIds = [];
            if (isset($task['tags']) && is_array($task['tags'])) {
                foreach ($task['tags'] as $tagName) {
                    if (isset($tasksData['tag_mapping'][$tagName])) {
                        $tagIds[] = $tasksData['tag_mapping'][$tagName];
                    }
                }
            }
            
            // Получение колонки для задачи, если указана
            $boardColumnId = null;
            if (isset($task['column']) && isset($boardId)) {
                $boardKey = 'board_' . $boardId;
                if (isset($tasksData['column_mapping'][$boardKey][$task['column']])) {
                    $boardColumnId = $tasksData['column_mapping'][$boardKey][$task['column']];
                }
            }
            
            $taskData = [
                'title' => $task['title'],
                'projectId' => $projectId,
                'boardId' => $boardId,
            ];
            
            if (isset($task['description'])) {
                $taskData['description'] = $task['description'];
            }
            
            if ($boardColumnId) {
                $taskData['boardColumnId'] = $boardColumnId;
            }
            
            if (!empty($tagIds)) {
                $taskData['tags'] = $tagIds;
            }
            
            $taskId = createTask($weeekClient, $cacheLoader, $taskData);
            if ($taskId) {
                $createdTasksMap[$task['title']] = $taskId;
            }
        }
        
        // Обновляем родительские связи для задач
        foreach ($tasksData['tasks'] as $index => $task) {
            if (isset($task['parent_task']) && $task['parent_task'] && isset($createdTasksMap[$task['parent_task']])) {
                // Задача имеет родительскую задачу, обновляем её
                if (isset($createdTasksMap[$task['title']])) {
                    // try {
                    //     $updateData = [
                    //         'parentId' => $createdTasksMap[$task['parent_task']]
                    //     ];
                        
                    //     $weeekClient->taskManager->tasks->update($createdTasksMap[$task['title']], $updateData);
                    //     echo "Задача '" . $task['title'] . "' привязана к родительской задаче '" . $task['parent_task'] . "'" . PHP_EOL;
                    // } catch (Exception $e) {
                    //     echo "Ошибка при обновлении родительской связи для задачи '" . $task['title'] . "': " . $e->getMessage() . PHP_EOL;
                    // }
                }
            }
        }
        
        echo "Импорт задач завершен." . PHP_EOL;
        
    } else {
        echo "В файле не найдены задачи или формат некорректен." . PHP_EOL;
    }
} else {
    echo "Файл с задачами не найден: " . $tasksFile . PHP_EOL;
}

/* Оставляем для тестирования
// Создаем доску
$boardData = [
    'name' => 'тест-доска-через-скрипт',
    'projectId' => 1,
];

try {
    // $response = $weeekClient->taskManager->boards->create($boardData);
    $taskData = [
        'title' => 'тест-задача-через-скрипт',
        'projectId' => 1,
        'boardId' => 2,
    ];
    $response = $weeekClient->taskManager->tasks->create($taskData);
    echo "Доска создана успешно: ID=" . $response->board->id . ", Название=" . $response->board->name . PHP_EOL;
    
    // Обновляем кеш досок
    $cacheLoader->reloadBoards();
    echo "Кеш досок обновлен" . PHP_EOL;
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . PHP_EOL;
} 
*/ 