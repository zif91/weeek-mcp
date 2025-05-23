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

// ID задачи для добавления записи времени
$taskId = 53; // Измените на нужный ID задачи

// Проверка существования задачи
try {
    $taskInfo = $tasksManager->getOne($taskId);
    
    if ($taskInfo) {
        echo "Задача найдена (ID=$taskId):" . PHP_EOL;
        echo "Название: " . $taskInfo['title'] . PHP_EOL;
        
        // Получаем текущего пользователя
        $userInfo = null;
        try {
            $userResponse = $weeekClient->user->getProfile();
            $userInfo = $userResponse->user;
            echo "Пользователь: " . $userInfo->name . " (ID: " . $userInfo->id . ")" . PHP_EOL;
        } catch (Exception $e) {
            echo "Ошибка при получении информации о пользователе: " . $e->getMessage() . PHP_EOL;
        }
        
        if ($userInfo) {
            // Данные для добавления записи времени
            $timeEntryData = [
                'userId' => $userInfo->id,
                'date' => date('d.m.Y'), // Текущая дата в формате ДД.ММ.ГГГГ
                'duration' => 60, // Продолжительность в минутах (1 час)
                'comment' => 'Тестовая запись времени из скрипта',
                'isOvertime' => false
            ];
            
            echo PHP_EOL . "Добавляем запись времени:" . PHP_EOL;
            echo "Пользователь: " . $timeEntryData['userId'] . PHP_EOL;
            echo "Дата: " . $timeEntryData['date'] . PHP_EOL;
            echo "Продолжительность: " . $timeEntryData['duration'] . " минут" . PHP_EOL;
            echo "Комментарий: " . $timeEntryData['comment'] . PHP_EOL;
            echo "Сверхурочно: " . ($timeEntryData['isOvertime'] ? 'Да' : 'Нет') . PHP_EOL;
            
            // Добавление записи времени
            $result = $tasksManager->addTimeEntry($taskId, $timeEntryData);
            
            if ($result) {
                echo PHP_EOL . "Запись времени успешно добавлена!" . PHP_EOL;
            } else {
                echo PHP_EOL . "Ошибка при добавлении записи времени." . PHP_EOL;
                
                // Альтернативный способ через непосредственный HTTP запрос
                echo "Пробуем добавить запись времени через прямой HTTP запрос..." . PHP_EOL;
                
                try {
                    // Получаем доступ к HTTP клиенту через рефлексию
                    $reflection = new ReflectionProperty($weeekClient->taskManager, 'http');
                    $reflection->setAccessible(true);
                    $http = $reflection->getValue($weeekClient->taskManager);
                    
                    // Выполняем запрос к API
                    $endpoint = '/tm/tasks/' . $taskId . '/manual-time-tracking';
                    $response = $http->post($endpoint, $timeEntryData);
                    
                    echo "Запись времени успешно добавлена через прямой HTTP запрос!" . PHP_EOL;
                } catch (Exception $e) {
                    echo "Ошибка при прямом HTTP запросе: " . $e->getMessage() . PHP_EOL;
                }
            }
        }
    } else {
        echo "Задача с ID=$taskId не найдена или произошла ошибка при получении данных." . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . PHP_EOL;
} 