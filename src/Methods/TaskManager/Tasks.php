<?php
declare(strict_types=1);

namespace WeeekMcp\Methods\TaskManager;

use WeeekMcp\Methods\TaskManager;
use WeeekMcp\Utils\Cache;
use WeeekMcp\Utils\CacheLoader;
use Weeek\Client as WeeekClient;

class Tasks
{
    public $cache;
    public $cacheLoader;
    public function __construct(public TaskManager $taskManager, public ?int $projectId, public ?int $boardId)
    {
        $this->cache = $this->taskManager->cache;
        $this->cacheLoader = $taskManager->cacheLoader;
    }

    /**
     * Получение всех задач с возможностью фильтрации
     *
     * @param array $query Параметры запроса (day, startDate, endDate, projectId, boardId, boardColumnId и др.)
     * @return array Массив задач
     */
    public function getAll(array $query = []): array
    {
        try {
            $response = $this->taskManager->weeekClient->taskManager->tasks->getAll($query);
            
            $tasks = [];
            foreach ($response->tasks as $task) {
                $taskInfo = [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description ?? '',
                    'is_completed' => $task->isCompleted ?? false,
                    'project_id' => $task->projectId,
                    'board_id' => $task->boardId ?? null,
                    'board_column_id' => $task->boardColumnId ?? null,
                    'tags' => []
                ];
                
                // Добавляем информацию о тегах
                if (isset($task->tags) && is_array($task->tags)) {
                    foreach ($task->tags as $tag) {
                        // Проверяем, является ли тег объектом или просто ID тега
                        if (is_object($tag)) {
                            $taskInfo['tags'][] = [
                                'id' => $tag->id,
                                'title' => $tag->title,
                                'color' => $tag->color
                            ];
                        } else {
                            // Если это просто ID тега, попробуем найти информацию о нем
                            $tagId = (int)$tag;
                            $tagInfo = ['id' => $tagId, 'title' => "Тег #{$tagId}", 'color' => null];
                            
                            if ($this->cache->has('tags')) {
                                $tagsData = $this->cache->get('tags');
                                foreach ($tagsData['data'] as $cachedTag) {
                                    if ($cachedTag['id'] == $tagId) {
                                        $tagInfo = $cachedTag;
                                        break;
                                    }
                                }
                            }
                            
                            $taskInfo['tags'][] = $tagInfo;
                        }
                    }
                }
                
                $tasks[] = $taskInfo;
            }
            
            // Обновляем кеш задач
            $this->cache->set('tasks', [
                'lastUpdated' => time(),
                'data' => $tasks
            ]);
            
            return $tasks;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Создание новой задачи
     *
     * @param array $data Данные задачи
     * @return array|null Информация о созданной задаче или null в случае ошибки
     */
    public function create(array $data): ?array
    {
        try {
            $response = $this->taskManager->weeekClient->taskManager->tasks->create(isset($data['data']) ? $data['data'] : $data);
            
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return [
                'id' => $response->task->id,
                'title' => $response->task->title,
                'description' => $response->task->description ?? '',
                'project_id' => $response->task->projectId,
                'board_id' => $response->task->boardId ?? null,
                'board_column_id' => $response->task->boardColumnId ?? null
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Получение информации о конкретной задаче
     *
     * @param int $id ID задачи
     * @return array|null Информация о задаче или null в случае ошибки
     */
    public function getOne(int $id): ?array
    {
        try {
            $response = $this->taskManager->weeekClient->taskManager->tasks->getOne($id);
            
            return [
                'id' => $response->task->id,
                'title' => $response->task->title,
                'description' => $response->task->description ?? '',
                'is_completed' => $response->task->isCompleted ?? false,
                'project_id' => $response->task->projectId,
                'board_id' => $response->task->boardId ?? null,
                'board_column_id' => $response->task->boardColumnId ?? null
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Обновление задачи
     *
     * @param int $id ID задачи
     * @param array $data Данные для обновления
     * @return bool Результат операции
     */
    public function update(int $id, array $data): bool
    {
        try {
            if (empty($data)) {
                return false;
            }

            // Прямой вызов HTTP-метода update
            $endpoint = '/tm/tasks/' . $id;

            // Получаем доступ к HTTP клиенту через рефлексию
            $reflection = new \ReflectionProperty($this->taskManager->weeekClient->taskManager, 'http');
            $reflection->setAccessible(true);
            $http = $reflection->getValue($this->taskManager->weeekClient->taskManager);

            $response = $http->put($endpoint, $data);
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Отметка задачи как выполненной
     *
     * @param int $id ID задачи
     * @return bool Результат операции
     */
    public function complete(int $id): bool
    {
        try {
            $this->taskManager->weeekClient->taskManager->tasks->complete($id);
            
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Отметка задачи как невыполненной
     *
     * @param int $id ID задачи
     * @return bool Результат операции
     */
    public function unComplete(int $id): bool
    {
        try {
            $this->taskManager->weeekClient->taskManager->tasks->unComplete($id);
            
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Перемещение задачи на другую доску
     *
     * @param int $id ID задачи
     * @param int|null $boardId ID доски
     * @return array|null Информация об обновленной задаче или null в случае ошибки
     */
    public function updateBoard(int $id, ?int $boardId): ?array
    {
        try {
            $response = $this->taskManager->weeekClient->taskManager->tasks->updateBoard($id, $boardId);
            
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return [
                'id' => $response->task->id,
                'board_id' => $response->task->boardId
            ];
        } catch (\Exception $e) {
            \error_log('[Tasks::updateBoard] id=' . $id . ', boardId=' . \var_export($boardId, true) . ' error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Перемещение задачи в другую колонку доски
     *
     * @param int $id ID задачи
     * @param int $boardColumnId ID колонки доски
     * @param int|null $upperTaskId ID задачи, после которой нужно разместить текущую
     * @return bool Результат операции
     */
    public function updateBoardColumn(int $id, int $boardColumnId, ?int $upperTaskId = null): bool
    {
        try {
            $this->taskManager->weeekClient->taskManager->tasks->updateBoardColumn($id, $boardColumnId, $upperTaskId);
            
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return true;
        } catch (\Exception $e) {
            \error_log('[Tasks::updateBoardColumn] id=' . $id . ', boardColumnId=' . $boardColumnId . ', upperTaskId=' . \var_export($upperTaskId, true) . ' error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Удаление задачи
     *
     * @param int $id ID задачи
     * @return bool Результат операции
     */
    public function delete(int $id): bool
    {
        try {
            $this->taskManager->weeekClient->taskManager->tasks->destroy($id);
            
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Подписка на задачу
     *
     * @param int $id ID задачи
     * @param int|null $userId ID пользователя
     * @return bool Результат операции
     */
    public function subscribe(int $id, ?int $userId = null): bool
    {
        try {
            $this->taskManager->weeekClient->taskManager->tasks->subscribe($id, $userId);
            
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Добавление записи ручного учета времени для задачи
     *
     * @param int $taskId ID задачи
     * @param array $data Данные для записи времени (userId, date, duration, comment, isOvertime)
     * @return bool Результат операции
     */
    public function addTimeEntry(int $taskId, array $data): bool
    {
        try {
            // Проверка обязательных полей
            // if (!isset($data['userId']) || !isset($data['date']) || !isset($data['duration'])) {
            //     return false;
            // }

            // Формируем данные для запроса
            $requestData = [
                'userId' => $data['userId'] ?? null,
                'date' => $data['date'],
                'duration' => (int)$data['duration'],
                // 'id' => $data['id'] ?? '',
                'comment' => $data['comment'] ?? '',
                'isOvertime' => $data['isOvertime'] ?? false
            ];

            // Получаем доступ к HTTP клиенту через рефлексию
            $reflection = new \ReflectionProperty($this->taskManager->weeekClient->taskManager, 'http');
            $reflection->setAccessible(true);
            $http = $reflection->getValue($this->taskManager->weeekClient->taskManager);

            // Выполняем запрос к API
            $endpoint = '/tm/tasks/' . $taskId . '/time-entries';
            $response = $http->post($endpoint, $requestData);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Отписка от задачи
     *
     * @param int $id ID задачи
     * @param int|null $userId ID пользователя
     * @return bool Результат операции
     */
    public function unsubscribe(int $id, ?int $userId = null): bool
    {
        try {
            $this->taskManager->weeekClient->taskManager->tasks->unsubscribe($id, $userId);
            
            // Обновляем кеш задач
            $this->cacheLoader->reloadTasks();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}