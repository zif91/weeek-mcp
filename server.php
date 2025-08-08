<?php

// ВАЖНО: подавляем любые предупреждения/Deprecated/Notice в STDOUT,
// чтобы MCP-транспорт получал только валидный JSON. Логи пишем в файл.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/server_log.txt');
// Отключаем отображение шумных уровней в выводе (без E_STRICT, чтобы не триггерить Deprecated)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_WARNING & ~E_NOTICE);

require_once __DIR__ . '/vendor/autoload.php';

use Pronskiy\Mcp\Server;
use Weeek\Client as WeeekClient;
use WeeekMcp\Api\TasksApi;
use WeeekMcp\Utils\Cache;
use WeeekMcp\Utils\CacheLoader;
use WeeekMcp\Methods\TaskManager;


// Загрузка конфигурации
$configFile = __DIR__ . '/config.json';
$config = json_decode(file_get_contents($configFile), true);

// Получение значений из переменных окружения или из конфига
$envProjectId = getenv('WEEEK_PROJECT_ID');
$envBoardId = getenv('WEEEK_BOARD_ID');
$envUserId = getenv('WEEEK_USER_ID');

// Обновление конфигурации значениями из переменных окружения (если они заданы)
if ($envProjectId) {
    $config['default_project_id'] = $envProjectId;
}
if ($envBoardId) {
    $config['default_board_id'] = $envBoardId;
}
if($envUserId) {
    $config['default_user_id'] = $envUserId;
}
// Создание клиента Weeek API
$apiToken = getenv('WEEEK_API_TOKEN') ?: $config['auth']['weeek_api_token'];
$weeekClient = new WeeekClient($apiToken);

// Инициализация кеша и загрузка данных при запуске
$cache = Cache::getInstance();
$cacheLoader = new CacheLoader($weeekClient, $config);

$cacheLoader->loadAllData();

$taskManager = new TaskManager($weeekClient, $cacheLoader, $cache, $config['default_project_id']);

// Создание MCP сервера
$server = new Server('weeek-mcp-server');

// Регистрация инструмента создания задачи
$server->tool(
    'create_task',
    'Создает новую задачу в Weeek',
    function(string $title, ?string $description = null, ?string $project_id = null, ?string $board_id = null, ?string $board_column_id = null, ?string $parent_id = null) use ($weeekClient, $config, $cache, $cacheLoader, $taskManager) {
        try {
            $projectId = $project_id !== null ? (int)$project_id : (int)($config['default_project_id'] ?? 0);
            $boardId = $board_id !== null ? (int)$board_id : (isset($config['default_board_id']) ? (int)$config['default_board_id'] : null);
            
            $taskData = [
                'title' => $title,
                'projectId' => $projectId,
            ];
            
            // Добавляем board_id, если он указан
            if ($boardId) {
                $taskData['boardId'] = $boardId;
            }
            
            // Добавляем board_column_id, если он указан
            if ($board_column_id) {
                $taskData['boardColumnId'] = (int)$board_column_id;
            }
            
            if ($description) {
                $taskData['description'] = $description;
            }
            if ($parent_id) {
                $taskData['parentId'] = (int)$parent_id;
            }
            
            $task = $taskManager->tasks->create($taskData);
            
            if (!$task) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не удалось создать задачу',
                    'code' => 500
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'task' => $task
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'details' => $e instanceof \Weeek\Exceptions\ApiErrorException ? $e->getDetails() : null
            ]);
        }
    }
);

// Регистрация инструмента получения проектов
$server->tool(
    'get_projects',
    'Получает список проектов в Weeek',
    function() use ($weeekClient, $cache) {
        try {
            // Проверяем, есть ли данные в кеше
            if ($cache->has('projects')) {
                $cachedData = $cache->get('projects');
                
                // Вернуть кешированные данные, если они есть
                return json_encode([
                    'status' => 'success',
                    'source' => 'cache',
                    'lastUpdated' => date('Y-m-d H:i:s', $cachedData['lastUpdated']),
                    'total' => count($cachedData['data']),
                    'projects' => $cachedData['data']
                ]);
            }
            
            // Если данных нет в кеше, получаем из API
            $response = $weeekClient->taskManager->projects->getAll();
            
            $projects = [];
            foreach ($response->projects as $project) {
                $projects[] = [
                    'id' => $project->id,
                    'name' => isset($project->name) ? $project->name : (isset($project->title) ? $project->title : 'Без названия'),
                    'is_private' => $project->isPrivate ?? false
                ];
            }
            
            // Сохраняем в кеш
            $cache->set('projects', [
                'lastUpdated' => time(),
                'data' => $projects
            ]);
            
            return json_encode([
                'status' => 'success',
                'source' => 'api',
                'total' => count($projects),
                'projects' => $projects
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Регистрация инструмента получения задач
$server->tool(
    'get_tasks',
    'Получает задачи. Для фильтра по доске укажите одновременно project_id и board_id (иначе API может вернуть 0). Если передан только board_id, сервер попробует определить project_id автоматически.',
    function(?string $project_id = null, ?string $board_id = null, ?string $board_column_id = null) use ($taskManager, $cache, $config) {
        try {
            $queryParams = [];
            $projectId = $project_id !== null ? (int)$project_id : (isset($config['default_project_id']) ? (int)$config['default_project_id'] : null);
            $boardId = $board_id !== null ? (int)$board_id : (isset($config['default_board_id']) ? (int)$config['default_board_id'] : null);

            // Если указан board_id без project_id — попробуем определить projectId по кешу досок
            if ($boardId !== null && $projectId === null) {
                error_log("get_tasks: board_id={$boardId} без project_id — пытаемся определить проект по кешу досок");
                $boardInfo = $taskManager->boards->get((int)$boardId);
                if ($boardInfo && isset($boardInfo['project_id'])) {
                    $projectId = (int)$boardInfo['project_id'];
                    error_log("get_tasks: project_id определен по кешу: {$projectId}");
                } else {
                    // Явная ошибка: для фильтра по доске нужно знать проект
                    return json_encode([
                        'status' => 'error',
                        'code' => 400,
                        'error' => 'Для фильтрации по доске Weeek API требует указать project_id вместе с board_id',
                        'hint' => 'Укажите одновременно project_id и board_id или сначала вызовите get_boards(project_id), чтобы получить корректную пару'
                    ]);
                }
            }
            if ($projectId !== null) { $queryParams['projectId'] = $projectId; }
            if ($boardId !== null) { $queryParams['boardId'] = $boardId; }
            
            if ($board_column_id) { $queryParams['boardColumnId'] = (int)$board_column_id; }
            
            $tasks = $taskManager->tasks->getAll($queryParams);
            $resp = [
                'status' => 'success',
                'source' => 'api',
                'total' => count($tasks),
                'tasks' => $tasks
            ];

            // Диагностика: если пришло 0, а пользователь задал только один из параметров
            if (empty($tasks) && (($boardId !== null) ^ ($projectId !== null))) {
                $resp['note'] = 'Weeek API часто возвращает пусто, если фильтровать только по board_id или только по project_id. Укажите оба параметра.';
            }

            // Если project_id был определен автоматически — сообщим об этом
            if ($board_id !== null && $project_id === null && isset($queryParams['projectId'])) {
                $resp['inferred'] = [
                    'project_id' => $queryParams['projectId'],
                    'reason' => 'Определено по кешу досок по board_id'
                ];
            }

            return json_encode($resp);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Регистрация инструмента получения одной задачи
$server->tool(
    'get_task',
    'Получает информацию о задаче в Weeek',
    function(string $task_id) use ($taskManager) {
        try {
            $task = $taskManager->tasks->getOne((int)$task_id);
            
            if (!$task) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Задача не найдена',
                    'code' => 404
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'task' => $task
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Регистрация инструмента обновления задачи
$server->tool(
    'update_task',
    'Обновляет задачу в Weeek',
    function(string $task_id, ?string $title = null, ?string $description = null) use ($taskManager) {
        try {
            $taskData = [];
            
            if ($title !== null) {
                $taskData['title'] = $title;
            }
            
            if ($description !== null) {
                $taskData['description'] = $description;
            }
            
            if (empty($taskData)) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не указаны данные для обновления',
                    'code' => 400
                ]);
            }
            
            $result = $taskManager->tasks->update((int)$task_id, $taskData);
            
            if (!$result) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не удалось обновить задачу',
                    'code' => 500
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Задача успешно обновлена',
                'task_id' => $task_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Регистрация инструмента выполнения задачи
$server->tool(
    'complete_task',
    'Отмечает задачу как выполненную в Weeek',
    function(string $task_id) use ($taskManager) {
        try {
            $result = $taskManager->tasks->complete((int)$task_id);
            
            if (!$result) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не удалось отметить задачу как выполненную',
                    'code' => 500
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Задача отмечена как выполненная',
                'task_id' => $task_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Регистрация инструмента снятия отметки о выполнении задачи
$server->tool(
    'uncomplete_task',
    'Снимает отметку о выполнении задачи в Weeek',
    function(string $task_id) use ($taskManager) {
        try {
            $result = $taskManager->tasks->unComplete((int)$task_id);
            
            if (!$result) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не удалось снять отметку о выполнении задачи',
                    'code' => 500
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Отметка о выполнении задачи снята',
                'task_id' => $task_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Регистрация инструмента изменения доски задачи
$server->tool(
    'update_task_board',
    'Перемещает TM-задачу на другую доску. Важно: доска должна принадлежать тому же проекту, что и задача. Для задач, прикрепленных к CRM-сделке, используйте инструмент move_deal_task.',
    function(string $task_id, string $board_id) use ($taskManager) {
        try {
            error_log("update_task_board: task_id={$task_id}, board_id={$board_id}");
            $result = $taskManager->tasks->updateBoard((int)$task_id, (int)$board_id);
            if (!$result) {
                error_log("update_task_board: FAIL for task {$task_id} -> board {$board_id}");
            } else {
                error_log("update_task_board: OK new board_id=" . ($result['board_id'] ?? 'n/a'));
            }
            
            if (!$result) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не удалось переместить задачу на другую доску',
                    'code' => 500,
                    'hint' => 'Проверьте, что доска принадлежит тому же проекту. Если это CRM-задача внутри сделки, используйте move_deal_task.'
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Задача перемещена на другую доску',
                'task_id' => $task_id,
                'board_id' => $board_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Регистрация инструмента изменения колонки доски задачи
$server->tool(
    'update_task_board_column',
    'Перемещает TM-задачу в другую колонку доски. Убедитесь, что колонка принадлежит правильной доске/проекту. Для CRM-задач используйте move_deal_task.',
    function(string $task_id, string $board_column_id, ?string $upper_task_id = null) use ($taskManager) {
        try {
            $upperTaskId = $upper_task_id ? (int)$upper_task_id : null;
            error_log("update_task_board_column: task_id={$task_id}, board_column_id={$board_column_id}, upper_task_id=" . var_export($upperTaskId, true));
            $result = $taskManager->tasks->updateBoardColumn((int)$task_id, (int)$board_column_id, $upperTaskId);
            if (!$result) {
                error_log("update_task_board_column: FAIL for task {$task_id} -> column {$board_column_id}");
            } else {
                error_log("update_task_board_column: OK");
            }
            
            if (!$result) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не удалось переместить задачу в другую колонку доски',
                    'code' => 500,
                    'hint' => 'Проверьте корректность board_column_id и принадлежность к нужной доске/проекту. Если это CRM-задача, используйте move_deal_task.'
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Задача перемещена в другую колонку доски',
                'task_id' => $task_id,
                'board_column_id' => $board_column_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Перемещение задачи внутри сделки (CRM): POST /crm/deals/{id}/tasks/{taskId}/move
$server->tool(
    'move_deal_task',
    'CRM: перемещает задачу, прикрепленную к сделке, относительно previousTaskId. Используйте для задач в разделе CRM, а не для TM-задач.',
    function(string $deal_id, string $task_id, ?string $previous_task_id = null) use ($weeekClient) {
        try {
            $prevId = $previous_task_id !== null && $previous_task_id !== '' ? (int)$previous_task_id : null;
            error_log("move_deal_task: deal_id={$deal_id}, task_id={$task_id}, previous_task_id=" . var_export($prevId, true));
            $resp = $weeekClient->crm->deals->moveSubtask($deal_id, (int)$task_id, $prevId);

            return json_encode([
                'status' => 'success',
                'message' => 'Задача перемещена внутри сделки',
                'deal_id' => $deal_id,
                'task_id' => (int)$task_id,
                'previous_task_id' => $prevId
            ]);
        } catch (\Weeek\Exceptions\ApiErrorException $e) {
            error_log('move_deal_task ApiError: ' . $e->getMessage() . ' | details=' . json_encode($e->getDetails()));
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'details' => $e->getDetails(),
                'hint' => 'Убедитесь, что task_id принадлежит этой сделке. Для TM-задач используйте инструменты update_task_board и update_task_board_column.'
            ]);
        } catch (\Exception $e) {
            error_log('move_deal_task Exception: ' . $e->getMessage());
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
                'hint' => 'Это инструмент CRM. Для TM-задач используйте инструменты update_task_board и update_task_board_column.'
            ]);
        }
    }
);

// Регистрация инструмента удаления задачи
$server->tool(
    'delete_task',
    'Удаляет задачу в Weeek',
    function(string $task_id) use ($taskManager) {
        try {
            $result = $taskManager->tasks->delete((int)$task_id);
            
            if (!$result) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не удалось удалить задачу',
                    'code' => 500
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Задача успешно удалена',
                'task_id' => $task_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Инструменты для работы с досками (boards)
// Получение списка досок - исправлено с учетом типа возвращаемого значения
$server->tool(
    'get_boards',
    'Получает список досок. Рекомендуется передавать project_id: Weeek API возвращает доски в контексте проекта, а пары (project_id, board_id) далее используются для корректной фильтрации задач.',
    function(?string $project_id = null) use ($weeekClient, $config, $cache, $cacheLoader, $taskManager) {
        try {
            // Если данных нет в кеше или указан конкретный project_id
            $projectId = (int)($project_id ?: $config['default_project_id']);
           
            $boards = $taskManager->boards->getByProjectId($projectId);
        
            return json_encode([
                'status' => 'success',
                'source' => 'api',
                'total' => count($boards),
                'boards' => $boards
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
);

// Извлечем общую логику обновления кеша досок в переменную-функцию
$refreshBoardsCache = function($weeekClient, $cache) {
    // Получаем все проекты
    $projectsResponse = $weeekClient->taskManager->projects->getAll();
    
    $allBoards = [];
    
    // Для каждого проекта получаем доски
    foreach ($projectsResponse->projects as $project) {
        $pId = (int)$project->id;
        
        // Прямой вызов API через HTTP клиент
        $url = "/tm/boards?projectId={$pId}";
        
        // Получаем доступ к HTTP клиенту через рефлексию
        $reflection = new \ReflectionProperty($weeekClient->taskManager, 'http');
        $reflection->setAccessible(true);
        $http = $reflection->getValue($weeekClient->taskManager);
        
        // Выполняем запрос
        $boardsResponse = $http->get($url);
        
        if ($boardsResponse && isset($boardsResponse->boards) && is_array($boardsResponse->boards)) {
            foreach ($boardsResponse->boards as $board) {
                $boardId = $board->id ?? null;
                $boardName = $board->name ?? ($board->title ?? 'Без названия');
                
                if ($boardId) {
                    $allBoards[] = [
                        'id' => $boardId,
                        'title' => $boardName,
                        'project_id' => $pId
                    ];
                }
            }
        }
    }
    
    // Сохраняем в кеш
    $cache->set('boards', [
        'lastUpdated' => time(),
        'data' => $allBoards
    ]);
    
    return $allBoards;
};

// Инструмент для обновления кеша досок
$server->tool(
    'reload_boards_cache',
    'Обновляет кеш досок в Weeek',
    function() use ($weeekClient, $cache, $refreshBoardsCache, $cacheLoader) {
        try {
            // Вызываем два метода обновления кэша для максимальной надежности
            $cacheLoader->reloadBoards();
            $allBoards = $refreshBoardsCache($weeekClient, $cache);
            
            // Проверяем, что кэш обновился
            $cachedBoards = $cache->get('boards', ['data' => []]);
            $boardCount = count($cachedBoards['data']);
            
            return json_encode([
                'status' => 'success',
                'message' => 'Кеш досок успешно обновлен',
                'total_boards' => $boardCount,
                'last_updated' => date('Y-m-d H:i:s', $cachedBoards['lastUpdated'] ?? time())
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
);

// Создание доски
$server->tool(
    'create_board',
    'Создает новую доску в Weeek',
    function(string $title, ?string $project_id = null) use ($weeekClient, $config, $cache, $cacheLoader, $taskManager) {
        try {
            $projectId = (int)($project_id ?: $config['default_project_id']);
            
            $response = $taskManager->boards->create([
                'name' => $title,
                'projectId' => $projectId,
            ]);
            
            return json_encode([
                'status' => 'success',
                'board' => [
                    'id' => $response->board->id,
                    'title' => $response->board->name,
                    'project_id' => $response->board->projectId
                ]
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'details' => $e instanceof \Weeek\Exceptions\ApiErrorException ? $e->getDetails() : null
            ]);
        }
    }
);

// Обновление доски
$server->tool(
    'update_board',
    'Обновляет доску в Weeek',
    function(string $board_id, string $title) use ($weeekClient, $cache, &$refreshBoardsCache, $cacheLoader) {
        try {
            $boardData = [
                'name' => $title,
            ];
            
            $response = $weeekClient->taskManager->boards->update((int)$board_id, $boardData);
            
            // Обновляем кеш досок через CacheLoader
            $cacheLoader->reloadBoards();
            
            // Также обновляем кеш досок через старый метод для совместимости
            $refreshBoardsCache($weeekClient, $cache);
            
            return json_encode([
                'status' => 'success',
                'message' => 'Доска успешно обновлена',
                'board_id' => $board_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Удаление доски
$server->tool(
    'delete_board',
    'Удаляет доску в Weeek',
    function(string $board_id) use ($weeekClient, $cache, &$refreshBoardsCache, $cacheLoader) {
        try {
            $response = $weeekClient->taskManager->boards->destroy((int)$board_id);
            
            // Обновляем кеш досок через CacheLoader
            $cacheLoader->reloadBoards();
            
            // Также обновляем кеш досок через старый метод для совместимости
            $refreshBoardsCache($weeekClient, $cache);
            
            return json_encode([
                'status' => 'success',
                'message' => 'Доска успешно удалена',
                'board_id' => $board_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Инструменты для работы с тегами (tags)
// Получение списка тегов
$server->tool(
    'get_tags',
    'Получает список тегов в Weeek',
    function() use ($weeekClient, $cache) {
        try {
            // Проверяем, есть ли данные в кеше
            if ($cache->has('tags')) {
                $cachedData = $cache->get('tags');
                
                // Вернуть кешированные данные
                return json_encode([
                    'status' => 'success',
                    'source' => 'cache',
                    'lastUpdated' => date('Y-m-d H:i:s', $cachedData['lastUpdated']),
                    'total' => count($cachedData['data']),
                    'tags' => $cachedData['data']
                ]);
            }
            
            // Используем метод getAll
            $response = $weeekClient->workspace->tags->getAll();
            
            $tags = [];
            foreach ($response->tags as $tag) {
                $tags[] = [
                    'id' => $tag->id,
                    'title' => $tag->title,
                    'color' => $tag->color
                ];
            }
            
            // Сохраняем в кеш
            $cache->set('tags', [
                'lastUpdated' => time(),
                'data' => $tags
            ]);
            
            return json_encode([
                'status' => 'success',
                'source' => 'api',
                'total' => count($tags),
                'tags' => $tags
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
);

// Создание тега
$server->tool(
    'create_tag',
    'Создает новый тег в Weeek',
    function(string $title, ?string $color = null) use ($weeekClient, $cacheLoader) {
        try {
            $tagData = [
                'title' => $title,
            ];
            
            if ($color) {
                $tagData['color'] = $color;
            }
            
            $response = $weeekClient->workspace->tags->create($tagData);
            
            // Обновляем кеш тегов
            $cacheLoader->reloadTags();
            
            return json_encode([
                'status' => 'success',
                'tag' => [
                    'id' => $response->tag->id,
                    'title' => $response->tag->title,
                    'color' => $response->tag->color ?? null
                ]
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Обновление тега
$server->tool(
    'update_tag',
    'Обновляет тег в Weeek',
    function(string $tag_id, ?string $title = null, ?string $color = null) use ($weeekClient, $cacheLoader) {
        try {
            $tagData = [];
            
            if ($title) {
                $tagData['title'] = $title;
            }
            
            if ($color) {
                $tagData['color'] = $color;
            }
            
            $response = $weeekClient->workspace->tags->update((int)$tag_id, $tagData);
            
            // Обновляем кеш тегов
            $cacheLoader->reloadTags();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Тег успешно обновлен',
                'tag_id' => $tag_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Удаление тега
$server->tool(
    'delete_tag',
    'Удаляет тег в Weeek',
    function(string $tag_id) use ($weeekClient, $cacheLoader) {
        try {
            // Используем метод destroy
            $response = $weeekClient->workspace->tags->destroy((int)$tag_id);
            
            // Обновляем кеш тегов
            $cacheLoader->reloadTags();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Тег успешно удален',
                'tag_id' => $tag_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Инструмент для присоединения тега к задаче
$server->tool(
    'attach_tag_to_task',
    'Присоединяет тег к задаче в Weeek',
    function(string $task_id, string $tag_id) use ($weeekClient, $cacheLoader) {
        try {
            // Создаем экземпляр расширенного API для задач
            $tasksApi = new TasksApi($weeekClient);
            
            // Пытаемся присоединить тег к задаче
            $result = $tasksApi->attachTag((int)$task_id, (int)$tag_id);
            
            // Обновляем кеш задач
            $cacheLoader->reloadTasks();
            
            if ($result['success']) {
                return json_encode([
                    'status' => 'success',
                    'message' => "Тег {$tag_id} присоединен к задаче {$task_id}",
                    'task_id' => (int)$task_id,
                    'tag_id' => (int)$tag_id
                ]);
            } else {
                return json_encode([
                    'status' => 'error',
                    'error' => $result['error'],
                    'code' => $result['code']
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Создание задачи с тегом
$server->tool(
    'create_task_with_tag',
    'Создает новую задачу в Weeek с тегом',
    function(string $title, string $tag_id, ?string $description = null, ?string $project_id = null, ?string $board_id = null) use ($weeekClient, $config, $cacheLoader) {
        try {
            // Сначала создаем задачу
            $projectId = $project_id ?: $config['default_project_id'];
            $boardId = $board_id ?: ($config['default_board_id'] ?? null);
            
            $taskData = [
                'title' => $title,
                'projectId' => $projectId,
            ];
            
            if ($boardId) {
                $taskData['boardId'] = $boardId;
            }
            
            if ($description) {
                $taskData['description'] = $description;
            }
            
            $response = $weeekClient->taskManager->tasks->create($taskData);
            $taskId = $response->task->id;
            
            // Затем прикрепляем тег к задаче
            $tasksApi = new TasksApi($weeekClient);
            $tagResult = $tasksApi->attachTag($taskId, (int)$tag_id);
            
            // Обновляем кеш задач
            $cacheLoader->reloadTasks();
            
            if (!$tagResult['success']) {
                return json_encode([
                    'status' => 'partial',
                    'message' => 'Задача создана, но произошла ошибка при прикреплении тега',
                    'task' => [
                        'id' => $taskId,
                        'title' => $response->task->title,
                        'description' => $response->task->description ?? '',
                        'project_id' => $response->task->projectId,
                        'board_id' => $response->task->boardId ?? null
                    ],
                    'tag_error' => $tagResult['error']
                ]);
            }
            
            return json_encode([
                'status' => 'success',
                'message' => 'Задача создана и тег прикреплен',
                'task' => [
                    'id' => $taskId,
                    'title' => $response->task->title,
                    'description' => $response->task->description ?? '',
                    'project_id' => $response->task->projectId,
                    'board_id' => $response->task->boardId ?? null,
                    'tag_id' => (int)$tag_id
                ]
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'details' => $e instanceof \Weeek\Exceptions\ApiErrorException ? $e->getDetails() : null
            ]);
        }
    }
);

// Инструменты для работы с колонками досок (board columns)
// Получение списка колонок доски
$server->tool(
    'get_board_columns',
    'Получает список колонок доски в Weeek',
    function(?string $board_id = null) use ($weeekClient, $cache, $config, $cacheLoader) {
        try {
            // Если board_id не указан, пытаемся использовать значение по умолчанию из конфигурации
            $boardId = $board_id ?: ($config['default_board_id'] ?? null);
            
            if (!$boardId) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не указан ID доски (board_id)',
                    'code' => 400
                ]);
            }
            
            // Проверяем, есть ли данные в кеше
            if ($cache->has('board_columns')) {
                $cachedData = $cache->get('board_columns');
                $columns = $cachedData['data'];
                
                // Фильтруем по board_id
                $columns = array_filter($columns, function($column) use ($boardId) {
                    return $column['board_id'] == $boardId;
                });
                $columns = array_values($columns); // переиндексация массива
                
                error_log("get_board_columns: Найдено " . count($columns) . " колонок в кеше для доски {$boardId}");
                
                // Если в кеше нет колонок для этой доски, попробуем обновить кеш
                if (empty($columns)) {
                    error_log("get_board_columns: В кеше нет колонок для доски {$boardId}, обновляем кеш");
                    $cacheLoader->loadBoardColumns();
                    
                    // Проверяем кеш после обновления
                    $cachedData = $cache->get('board_columns');
                    $columns = $cachedData['data'];
                    
                    // Фильтруем по board_id снова
                    $columns = array_filter($columns, function($column) use ($boardId) {
                        return $column['board_id'] == $boardId;
                    });
                    $columns = array_values($columns);
                    
                    // Если все еще пусто, получаем данные напрямую из API
                    if (empty($columns)) {
                        error_log("get_board_columns: После обновления кеша колонки для доски {$boardId} все еще не найдены");
                    } else {
                        error_log("get_board_columns: После обновления кеша найдено " . count($columns) . " колонок для доски {$boardId}");
                        return json_encode([
                            'status' => 'success',
                            'source' => 'cache_refresh',
                            'lastUpdated' => date('Y-m-d H:i:s', $cachedData['lastUpdated']),
                            'total' => count($columns),
                            'boardColumns' => $columns
                        ]);
                    }
                } else {
                    // Вернуть кешированные данные
                    return json_encode([
                        'status' => 'success',
                        'source' => 'cache',
                        'lastUpdated' => date('Y-m-d H:i:s', $cachedData['lastUpdated']),
                        'total' => count($columns),
                        'boardColumns' => $columns
                    ]);
                }
            }
            
            // Если данных нет в кеше или после обновления кеша, получаем из API
            $tasksApi = new TasksApi($weeekClient);
            $result = $tasksApi->getBoardColumns((int)$boardId);
            
            error_log("get_board_columns: Получен результат от TasksApi: " . json_encode($result));
            
            if (!$result['success']) {
                error_log("get_board_columns: Ошибка получения колонок: " . ($result['error'] ?? 'Неизвестная ошибка'));
                return json_encode([
                    'status' => 'error',
                    'error' => $result['error'] ?? 'Ошибка получения колонок',
                    'code' => $result['code'] ?? 500
                ]);
            }
            
            $columns = [];
            foreach ($result['boardColumns'] as $column) {
                error_log("get_board_columns: Обработка колонки: " . json_encode($column));
                
                // Преобразуем в объект, если это массив
                if (is_array($column)) {
                    $column = (object)$column;
                    error_log("get_board_columns: Колонка преобразована из массива в объект");
                }
                
                // Проверяем наличие обязательных полей
                if (!isset($column->id) || !isset($column->name)) {
                    error_log("get_board_columns: Пропуск колонки без id или name: " . json_encode($column));
                    continue;
                }
                
                // Получаем boardId из колонки или используем ID текущей доски
                $columnBoardId = $column->boardId ?? $boardId;
                
                $columns[] = [
                    'id' => $column->id,
                    'name' => $column->name,
                    'board_id' => $columnBoardId
                ];
                error_log("get_board_columns: Колонка добавлена: {$column->id} - {$column->name}");
            }
            
            // Добавляем полученные колонки в кеш, сохраняя существующие колонки других досок
            if ($cache->has('board_columns')) {
                $cachedData = $cache->get('board_columns');
                $allColumns = $cachedData['data'];
                
                // Удаляем колонки текущей доски (если есть)
                $allColumns = array_filter($allColumns, function($column) use ($boardId) {
                    return $column['board_id'] != $boardId;
                });
                
                // Добавляем новые колонки
                $allColumns = array_merge($allColumns, $columns);
                
                $cache->set('board_columns', [
                    'lastUpdated' => time(),
                    'data' => $allColumns
                ]);
                
                error_log("get_board_columns: Обновлен кеш колонок. Всего колонок: " . count($allColumns));
            } else {
                // Если кеша нет, создаем новый
                $cache->set('board_columns', [
                    'lastUpdated' => time(),
                    'data' => $columns
                ]);
                
                error_log("get_board_columns: Создан новый кеш колонок. Всего колонок: " . count($columns));
            }
            
            return json_encode([
                'status' => 'success',
                'source' => 'api',
                'total' => count($columns),
                'boardColumns' => $columns
            ]);
        } catch (Exception $e) {
            error_log("get_board_columns: Исключение: " . $e->getMessage());
            error_log("get_board_columns: " . $e->getTraceAsString());
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Создание колонки доски
$server->tool(
    'create_board_column',
    'Создает новую колонку доски в Weeek',
    function(string $name, string $board_id) use ($weeekClient, $cacheLoader) {
        try {
            $tasksApi = new TasksApi($weeekClient);
            $result = $tasksApi->createBoardColumn((int)$board_id, $name);
            
            if (!$result['success']) {
                return json_encode([
                    'status' => 'error',
                    'error' => $result['error'],
                    'code' => $result['code']
                ]);
            }
            
            // Обновляем кеш колонок досок
            $cacheLoader->loadBoardColumns();
            
            $column = $result['boardColumn'];
            
            return json_encode([
                'status' => 'success',
                'boardColumn' => [
                    'id' => $column->id,
                    'name' => $column->name,
                    'board_id' => $column->boardId
                ]
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Обновление колонки доски
$server->tool(
    'update_board_column',
    'Обновляет колонку доски в Weeek',
    function(string $column_id, string $name) use ($weeekClient, $cacheLoader) {
        try {
            $tasksApi = new TasksApi($weeekClient);
            $result = $tasksApi->updateBoardColumn((int)$column_id, $name);
            
            if (!$result['success']) {
                return json_encode([
                    'status' => 'error',
                    'error' => $result['error'],
                    'code' => $result['code']
                ]);
            }
            
            // Обновляем кеш колонок досок
            $cacheLoader->loadBoardColumns();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Колонка успешно обновлена',
                'column_id' => $column_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Удаление колонки доски
$server->tool(
    'delete_board_column',
    'Удаляет колонку доски в Weeek',
    function(string $column_id) use ($weeekClient, $cacheLoader) {
        try {
            $tasksApi = new TasksApi($weeekClient);
            $result = $tasksApi->deleteBoardColumn((int)$column_id);
            
            if (!$result['success']) {
                return json_encode([
                    'status' => 'error',
                    'error' => $result['error'],
                    'code' => $result['code']
                ]);
            }
            
            // Обновляем кеш колонок досок
            $cacheLoader->loadBoardColumns();
            
            return json_encode([
                'status' => 'success',
                'message' => 'Колонка успешно удалена',
                'column_id' => $column_id
            ]);
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }
);

// Регистрация инструмента для добавления записи ручного учета времени в задаче
$server->tool(
    'add_task_time_entry',
    'Добавляет запись ручного учета времени для задачи в Weeek',
    function(string $task_id, ?string $user_id, string $date, int $duration, ?string $comment = null, ?bool $is_overtime = false) use ($weeekClient, $cacheLoader, $cache, $taskManager, $config) {
        try {
            $user_id = $user_id ?? ($config['default_user_id'] ?? null);
            if (empty($task_id) || empty($user_id) || empty($date) || $duration <= 0) {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не указаны обязательные параметры',
                    'code' => 400
                ]);
            }
            
            $data = [
                'userId' => $user_id,
                'date' => $date,
                'duration' => $duration,
                'comment' => $comment ?? '',
                'isOvertime' => $is_overtime ?? false
            ];
            
            // Если у нас есть инициализированные Tasks в TaskManager
            $result = $taskManager->tasks->addTimeEntry((int)$task_id, $data);
            
            if ($result) {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Запись времени успешно добавлена',
                    'data' => [
                        'task_id' => $task_id,
                        'user_id' => $user_id,
                        'date' => $date,
                        'duration' => $duration,
                        'comment' => $comment,
                        'is_overtime' => $is_overtime
                    ]
                ]);
            } else {
                return json_encode([
                    'status' => 'error',
                    'error' => 'Не удалось добавить запись времени',
                    'code' => 500
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'code' => 500
            ]);
        }
    }
);

// Запуск сервера
$server->run();