<?php

namespace WeeekMcp\Api;

use Weeek\Client as WeeekClient;

/**
 * Расширение API для задач Weeek, добавляющее методы, которых нет в стандартном API клиенте
 */
class TasksApi
{
    /**
     * @var WeeekClient
     */
    private $weeekClient;

    /**
     * @param WeeekClient $weeekClient
     */
    public function __construct(WeeekClient $weeekClient)
    {
        $this->weeekClient = $weeekClient;
    }

    /**
     * Обновляет задачу (включая теги)
     *
     * @param int $taskId ID задачи
     * @param array $data Данные для обновления
     * @return mixed
     * @throws \Exception
     */
    public function updateTask(int $taskId, array $data)
    {
        // Используем метод put базового класса Endpoint
        // Путь к API задач в Weeek
        $endpoint = '/tm/tasks/' . $taskId;
        
        try {
            $response = $this->weeekClient->http->put($endpoint, $data);
            return $response;
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Присоединяет тег к задаче
     *
     * @param int $taskId ID задачи
     * @param int $tagId ID тега
     * @return array Результат операции
     */
    public function attachTag(int $taskId, int $tagId): array
    {
        try {
            // Получаем информацию о задаче
            $taskResponse = $this->weeekClient->taskManager->tasks->get($taskId);
            $task = $taskResponse->task;
            
            // Создаем массив ID тегов
            $tagIds = [];
            
            // Если у задачи уже есть теги, добавляем их в массив
            if (isset($task->tags) && is_array($task->tags)) {
                foreach ($task->tags as $tag) {
                    $tagIds[] = $tag->id;
                }
            }
            
            // Проверяем, не прикреплен ли уже этот тег
            if (in_array($tagId, $tagIds)) {
                return [
                    'success' => true,
                    'message' => 'Тег уже прикреплен к задаче'
                ];
            }
            
            // Добавляем новый тег
            $tagIds[] = $tagId;
            
            // Обновляем задачу с новыми тегами
            $response = $this->weeekClient->taskManager->tasks->update($taskId, [
                'tags' => $tagIds
            ]);
            
            return [
                'success' => true,
                'message' => 'Тег успешно прикреплен'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }

    /**
     * Получает список колонок доски
     *
     * @param int $boardId ID доски
     * @return array Результат операции
     */
    public function getBoardColumns(int $boardId): array
    {
        try {
            // Путь к API колонок досок в Weeek
            $endpoint = '/tm/board-columns?boardId=' . $boardId;
            
            error_log("TasksApi: Выполняем запрос к {$endpoint}");
            
            $response = $this->weeekClient->http->get($endpoint);
            
            error_log("TasksApi: Тип ответа: " . gettype($response));
            error_log("TasksApi: Ответ: " . json_encode($response));
            
            if (!$response) {
                error_log("TasksApi: Пустой ответ от API");
                return [
                    'success' => false,
                    'error' => 'Пустой ответ от API',
                    'code' => 500
                ];
            }
            
            // Преобразуем ответ в структурированный формат
            $boardColumns = [];
            
            // Вариант 1: объектный формат с полем boardColumns
            if (is_object($response) && isset($response->boardColumns)) {
                error_log("TasksApi: Найдены колонки в формате response->boardColumns");
                $boardColumns = $response->boardColumns;
            } 
            // Вариант 2: массив с ключом 'boardColumns'
            elseif (is_array($response) && isset($response['boardColumns'])) {
                error_log("TasksApi: Найдены колонки в формате response['boardColumns']");
                $boardColumns = $response['boardColumns'];
            }
            // Вариант 3: сам ответ является массивом колонок
            elseif (is_array($response) && !empty($response) && isset($response[0])) {
                error_log("TasksApi: Ответ сам является массивом колонок");
                $boardColumns = $response;
            }
            // Вариант 4: поле columns вместо boardColumns
            elseif (is_object($response) && isset($response->columns)) {
                error_log("TasksApi: Найдены колонки в формате response->columns");
                $boardColumns = $response->columns;
            }
            // Вариант 5: поле items как часто встречающийся паттерн для списков
            elseif (is_object($response) && isset($response->items)) {
                error_log("TasksApi: Найдены колонки в формате response->items");
                $boardColumns = $response->items;
            }
            else {
                error_log("TasksApi: Не удалось найти колонки в ответе API");
                error_log("TasksApi: Доступные свойства: " . implode(", ", array_keys((array)$response)));
                return [
                    'success' => false,
                    'error' => 'В ответе не найдены данные о колонках',
                    'code' => 500
                ];
            }
            
            // Проверяем, что колонки представляют собой массив
            if (!is_array($boardColumns)) {
                error_log("TasksApi: boardColumns не является массивом: " . gettype($boardColumns));
                
                // Попытка преобразовать объект в массив
                if (is_object($boardColumns)) {
                    $boardColumns = [$boardColumns];
                    error_log("TasksApi: boardColumns преобразован из объекта в массив из одного элемента");
                } else {
                    return [
                        'success' => false,
                        'error' => 'Неверный формат данных boardColumns',
                        'code' => 500
                    ];
                }
            }
            
            error_log("TasksApi: Успешно получены колонки: " . count($boardColumns));
            
            return [
                'success' => true,
                'boardColumns' => $boardColumns
            ];
        } catch (\Exception $e) {
            error_log("TasksApi: Ошибка при получении колонок: " . $e->getMessage());
            error_log("TasksApi: " . $e->getTraceAsString());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }
    
    /**
     * Создает новую колонку доски
     *
     * @param int $boardId ID доски
     * @param string $name Название колонки
     * @return array Результат операции
     */
    public function createBoardColumn(int $boardId, string $name): array
    {
        try {
            // Путь к API колонок досок в Weeek
            $endpoint = '/tm/board-columns';
            
            $data = [
                'boardId' => $boardId,
                'name' => $name
            ];
            
            $response = $this->weeekClient->http->post($endpoint, $data);
            
            return [
                'success' => true,
                'boardColumn' => $response->boardColumn ?? null
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }
    
    /**
     * Обновляет колонку доски
     *
     * @param int $columnId ID колонки
     * @param string $name Новое название колонки
     * @return array Результат операции
     */
    public function updateBoardColumn(int $columnId, string $name): array
    {
        try {
            // Путь к API колонок досок в Weeek
            $endpoint = '/tm/board-columns/' . $columnId;
            
            $data = [
                'name' => $name
            ];
            
            $response = $this->weeekClient->http->put($endpoint, $data);
            
            return [
                'success' => true,
                'message' => 'Колонка успешно обновлена'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }
    
    /**
     * Удаляет колонку доски
     *
     * @param int $columnId ID колонки
     * @return array Результат операции
     */
    public function deleteBoardColumn(int $columnId): array
    {
        try {
            // Путь к API колонок досок в Weeek
            $endpoint = '/tm/board-columns/' . $columnId;
            
            $response = $this->weeekClient->http->delete($endpoint);
            
            return [
                'success' => true,
                'message' => 'Колонка успешно удалена'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }
} 