<?php
declare(strict_types=1);

namespace WeeekMcp\Utils;

use Weeek\Client as WeeekClient;
use WeeekMcp\Utils\Cache;

class CacheLoader
{
    public WeeekClient $weeekClient;
    public Cache $cache;
    public array $config;

    public function __construct(WeeekClient $weeekClient, array $config)
    {
        $this->weeekClient = $weeekClient;
        $this->cache = Cache::getInstance();
        $this->config = $config;
    }

    /**
     * Возвращает HTTP-клиент Weeek SDK. Если публичного свойства нет,
     * использует рефлексию для доступа к внутреннему клиенту.
     */
    private function getHttpClient(): object
    {
        // Попытка использовать публичное свойство, если оно существует
        if (property_exists($this->weeekClient, 'http') && $this->weeekClient->http) {
            /** @var object $http */
            $http = $this->weeekClient->http;
            return $http;
        }

        // Фолбэк: достать http-клиент через taskManager
        if (property_exists($this->weeekClient, 'taskManager') && $this->weeekClient->taskManager) {
            $reflection = new \ReflectionProperty($this->weeekClient->taskManager, 'http');
            $reflection->setAccessible(true);
            $http = $reflection->getValue($this->weeekClient->taskManager);
            if (!$http) {
                throw new \RuntimeException('Weeek HTTP client is not available');
            }
            return $http;
        }

        throw new \RuntimeException('Weeek HTTP client is not accessible');
    }

    public function loadAllData(): bool
    {
        $this->loadProjects();
        $this->loadTags();
        $this->loadBoards();
        $this->loadBoardColumns();
        $this->loadTasks();
        
        return true;
    }

    // Публичный метод для обновления тегов
    public function reloadTags(): bool
    {
        return $this->loadTags();
    }

    // Публичный метод для обновления досок
    public function reloadBoards(): bool
    {
        return $this->loadBoards();
    }

    // Публичный метод для обновления задач
    public function reloadTasks(): bool
    {
        return $this->loadTasks();
    }

    public function loadProjects(): bool
    {
        try {
            $response = $this->weeekClient->taskManager->projects->getAll();
            
            $projects = [];
            foreach ($response->projects as $project) {
                $projects[] = [
                    'id' => $project->id,
                    'name' => isset($project->name) ? $project->name : (isset($project->title) ? $project->title : 'Без названия'),
                    'is_public' => $project->ispublic ?? false
                ];
            }
            
            $this->cache->set('projects', [
                'lastUpdated' => time(),
                'data' => $projects
            ]);
            
            return true;
        } catch (\Exception $e) {
            error_log("Ошибка загрузки проектов в кеш: " . $e->getMessage());
            return false;
        }
    }

    public function loadTags(): bool
    {
        try {
            $response = $this->weeekClient->workspace->tags->getAll();
            
            $tags = [];
            foreach ($response->tags as $tag) {
                $tags[] = [
                    'id' => $tag->id,
                    'title' => $tag->title,
                    'color' => $tag->color
                ];
            }
            
            $this->cache->set('tags', [
                'lastUpdated' => time(),
                'data' => $tags
            ]);
            
            return true;
        } catch (\Exception $e) {
            error_log("Ошибка загрузки тегов в кеш: " . $e->getMessage());
            return false;
        }
    }

    public function loadBoards(): bool
    {
        try {
            error_log("[CacheLoader::loadBoards] Начинаем загрузку досок в кеш");
            $allBoards = [];
            
            // Получаем все проекты
            $projects = $this->cache->get('projects', ['data' => []]);
            
            // вместо использования метода getAll, который неправильно типизирован
            foreach ($projects['data'] as $project) {
                $projectId = (int)$project['id'];
            
                try {
                    // Прямой вызов API через HTTP клиент
                    $url = "/tm/boards?projectId={$projectId}";
                    
                    error_log("[CacheLoader::loadBoards] Выполняем запрос к API: {$url}");
                    
                    // Получаем HTTP-клиент (публичный или через рефлексию)
                    $http = $this->getHttpClient();
                    
                    // Выполняем запрос
                    $response = $http->get($url);
                    
                    if ($response && is_array($response) && isset($response['boards']) && is_array($response['boards'])) {
                        $boards = $response['boards'];
                        error_log("[CacheLoader::loadBoards] Найдено досок для проекта {$projectId}: " . count($boards));
                        
                        foreach ($boards as $board) {
                            $boardId = $board['id'] ?? null;
                            $boardName = $board['name'] ?? ($board['title'] ?? 'Без названия');
                            
                            if ($boardId) {
                                $allBoards[] = [
                                    'id' => $boardId,
                                    'title' => $boardName,
                                    'project_id' => $projectId
                                ];
                                error_log("[CacheLoader::loadBoards] Доска добавлена в кэш: {$boardId} - {$boardName}");
                            } else {
                                error_log("[CacheLoader::loadBoards] Пропущена доска без ID: " . json_encode($board));
                            }
                        }
                    } else {
                        error_log("[CacheLoader::loadBoards] Для проекта {$projectId} доски не найдены или ответ имеет неправильный формат");
                        error_log("[CacheLoader::loadBoards] Полученный ответ: " . json_encode($response));
                    }
                } catch (\Exception $e) {
                    // Просто логируем ошибку и продолжаем
                    error_log("[CacheLoader::loadBoards] Ошибка при получении досок для проекта {$projectId}: " . $e->getMessage());
                    error_log("[CacheLoader::loadBoards] Трассировка: " . $e->getTraceAsString());
                }
            }
            
            
            // Сохраняем в кеш только если нашли хотя бы одну доску
            // или если кеш еще не инициализирован
            if (count($allBoards) > 0 || !$this->cache->has('boards')) {
                $this->cache->set('boards', [
                    'lastUpdated' => time(),
                    'data' => $allBoards
                ]);
                error_log("[CacheLoader::loadBoards] Доски успешно сохранены в кеш: " . count($allBoards) . " досок");
            } else {
                error_log("[CacheLoader::loadBoards] Не обновляем кеш, т.к. не найдено ни одной доски, а кеш уже существует");
            }
            
            return true;
        } catch (\Exception $e) {
            error_log("[CacheLoader::loadBoards] Критическая ошибка загрузки досок в кеш: " . $e->getMessage());
            error_log("[CacheLoader::loadBoards] Трассировка: " . $e->getTraceAsString());
            return false;
        }
    }

    public function loadBoardColumns(): bool
    {
        try {
            $allBoardColumns = [];
            
            // Получаем все доски
            $boards = $this->cache->get('boards', ['data' => []]);
            
            error_log("Загрузка колонок досок в кеш. Найдено досок: " . count($boards['data']));
            
            // Для каждой доски получаем ее колонки
            foreach ($boards['data'] as $board) {
                $boardId = (int)$board['id'];
                
                error_log("Загрузка колонок для доски {$boardId} ({$board['title']})");
                
                try {
                    // Прямой вызов API через HTTP клиент
                    $url = "/tm/board-columns?boardId={$boardId}";
                    
                    error_log("Выполняем запрос к API: {$url}");
                    
                    // Получаем HTTP-клиент (публичный или через рефлексию)
                    $http = $this->getHttpClient();
                    
                    // Выполняем запрос
                    $response = $http->get($url);
                    
                    // Дебаг-информация о полученном ответе
                    error_log("Тип ответа от API: " . gettype($response));
                    error_log("Получен ответ от API: " . json_encode($response));
                    
                    // Преобразуем ответ в массив для унификации обработки
                    $responseArray = [];
                    if (is_object($response)) {
                        $responseArray = (array)$response;
                        error_log("Ответ преобразован из объекта в массив. Ключи: " . implode(", ", array_keys($responseArray)));
                    } elseif (is_array($response)) {
                        $responseArray = $response;
                        error_log("Ответ уже в формате массива. Ключи: " . implode(", ", array_keys($responseArray)));
                    } else {
                        error_log("Неожиданный формат ответа: " . gettype($response));
                        continue;
                    }
                    
                    // Пытаемся найти колонки в разных форматах ответа
                    $columns = null;
                    
                    // Вариант 1: объектный формат - $response->boardColumns
                    if (isset($response->boardColumns)) {
                        $columns = $response->boardColumns;
                        error_log("Найдены колонки в формате response->boardColumns");
                    } 
                    // Вариант 2: массив с ключом 'boardColumns'
                    elseif (isset($responseArray['boardColumns'])) {
                        $columns = $responseArray['boardColumns'];
                        error_log("Найдены колонки в формате responseArray['boardColumns']");
                    }
                    // Вариант 3: сам ответ является массивом колонок
                    elseif (isset($responseArray[0]) && is_array($responseArray) && !empty($responseArray)) {
                        $columns = $responseArray;
                        error_log("Ответ сам является массивом колонок");
                    } 
                    
                    if ($columns !== null) {
                        if (!is_array($columns)) {
                            if (is_object($columns)) {
                                $columns = (array)$columns;
                                error_log("Колонки преобразованы из объекта в массив");
                            } else {
                                error_log("Колонки не являются ни массивом, ни объектом: " . gettype($columns));
                                continue;
                            }
                        }
                        
                        error_log("Найдено колонок для доски {$boardId}: " . count($columns));
                        
                        foreach ($columns as $column) {
                            error_log("Обработка колонки: " . json_encode($column));
                            
                            // Преобразуем в объект, если это массив
                            if (is_array($column)) {
                                $column = (object)$column;
                                error_log("Колонка преобразована из массива в объект");
                            }
                            
                            // Проверяем наличие необходимых полей
                            if (!isset($column->id) || !isset($column->name)) {
                                error_log("Пропуск колонки без id или name: " . json_encode($column));
                                continue;
                            }
                            
                            // Получаем boardId из колонки или используем ID текущей доски
                            $columnBoardId = $column->boardId ?? $boardId;
                            
                            $allBoardColumns[] = [
                                'id' => $column->id,
                                'name' => $column->name,
                                'board_id' => $columnBoardId
                            ];
                            error_log("Колонка добавлена в кэш: {$column->id} - {$column->name}");
                        }
                    } else {
                        error_log("Для доски {$boardId} колонки не найдены в ответе API");
                    }
                } catch (\Exception $e) {
                    // Просто логируем ошибку и продолжаем
                    error_log("Ошибка при получении колонок для доски {$boardId}: " . $e->getMessage());
                    error_log("Трассировка: " . $e->getTraceAsString());
                }
            }
            
            error_log("Всего найдено колонок по всем доскам: " . count($allBoardColumns));
            
            $this->cache->set('board_columns', [
                'lastUpdated' => time(),
                'data' => $allBoardColumns
            ]);
            
            error_log("Колонки досок успешно сохранены в кеш");
            
            return true;
        } catch (\Exception $e) {
            error_log("Ошибка загрузки колонок досок в кеш: " . $e->getMessage());
            error_log("Трассировка: " . $e->getTraceAsString());
            return false;
        }
    }

    public function loadTasks(): bool
    {
        try {
            $response = $this->weeekClient->taskManager->tasks->getAll();
            
            $tasks = [];
            foreach ($response->tasks as $task) {
                $taskInfo = [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description ?? '',
                    'is_completed' => $task->isCompleted ?? false,
                    'project_id' => $task->projectId,
                    'board_id' => $task->boardId,
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
                            // Если это просто ID тега, попробуем найти информацию о нем в кеше тегов
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
            
            $this->cache->set('tasks', [
                'lastUpdated' => time(),
                'data' => $tasks
            ]);
            
            return true;
        } catch (\Exception $e) {
            error_log("Ошибка загрузки задач в кеш: " . $e->getMessage());
            return false;
        }
    }
} 