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

// ID задачи для обновления
$taskId = 53; // Измените на нужный ID задачи


// Получаем текущие данные задачи через менеджер задач
try {
    $taskInfoArr = $tasksManager->getOne($taskId);
    
    if ($taskInfoArr) {
       
        // echo "Текущие данные задачи (ID=$taskId):" . PHP_EOL;
        // echo "Название: " . $taskInfoArr['title'] . PHP_EOL;
        // echo "Описание: " . ($taskInfoArr['description'] ?? 'не указано') . PHP_EOL;
        // echo "Статус выполнения: " . ($taskInfoArr['is_completed'] ? 'выполнена' : 'не выполнена') . PHP_EOL;
        // echo "ID проекта: " . $taskInfoArr['project_id'] . PHP_EOL;
        // echo "ID доски: " . ($taskInfoArr['board_id'] ?? 'не указан') . PHP_EOL;
        // echo "ID колонки: " . ($taskInfoArr['board_column_id'] ?? 'не указана') . PHP_EOL;
    
        // Данные для обновления задачи
        $updateData = [
            // 'title' => 'Обновленное название задачи #' . time(), // Добавляем timestamp для уникальности
            'description' => 'Обновленное описание задачи. Тестирование API обновления через Tasks.php',
            'priority' => 2, // 0 - низкий, 1 - средний, 2 - высокий
            'type' => 'action', // action, bug, question, documentation, info
            'parentId' => 52,
        ];
        
        // // Добавление дат (опционально)
        $updateData['startDate'] = date('Y-m-d');
        $updateData['dueDate'] = date('Y-m-d', strtotime('+1 month'));
        
        // // Добавление тегов (если нужно)
        // // $updateData['tags'] = [1, 2]; // ID тегов для прикрепления
        
        // // Добавление времени (опционально)
        $timeData = [
            'duration' => 10, // Время в минутах
            'date' => date('Y-m-d'),
            'comment' => 'Тестовое время 1',
            'isOvertime' => false,
            'userId' => '9b1d793b-fa06-4b31-9f10-845673ca9655',
        ];
        
        // Обновление задачи через менеджер задач
        $result = $tasksManager->update($taskId, $updateData);
        $resultTime = $tasksManager->addTimeEntry($taskId, $timeData);
        
        if ($result) {
            echo PHP_EOL . "Задача успешно обновлена!" . PHP_EOL;
            
            // Получаем обновленные данные задачи
            $updatedTaskInfoArr = $tasksManager->getOne($taskId);
            
            if ($updatedTaskInfoArr) {
                echo PHP_EOL . "Обновленные данные задачи:" . PHP_EOL;
                echo "Название: " . $updatedTaskInfoArr['title'] . PHP_EOL;
                echo "Описание: " . ($updatedTaskInfoArr['description'] ?? 'не указано') . PHP_EOL;
                echo "Статус выполнения: " . ($updatedTaskInfoArr['is_completed'] ? 'выполнена' : 'не выполнена') . PHP_EOL;
                echo "ID проекта: " . $updatedTaskInfoArr['project_id'] . PHP_EOL;
                echo "ID доски: " . ($updatedTaskInfoArr['board_id'] ?? 'не указан') . PHP_EOL;
                echo "ID колонки: " . ($updatedTaskInfoArr['board_column_id'] ?? 'не указана') . PHP_EOL;
                echo "Время: " . ($updatedTaskInfoArr['timeTracking'] ?? 'не указано') . PHP_EOL;
            }
        } else {
            echo "Ошибка при обновлении задачи через Tasks.php" . PHP_EOL;
        }
    } else {
        echo "Задача с ID=$taskId не найдена или произошла ошибка при получении данных через Tasks.php." . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . PHP_EOL;
} 