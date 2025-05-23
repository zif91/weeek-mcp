<?php

namespace WeeekMcp;

use Exception;
use Weeek\Client as WeeekClient;
use WeeekMcp\Utils\MpcReader;
use WeeekMcp\Utils\MpcWriter;

/**
 * Основной класс MCP сервера для Weeek
 */
class WeeekMcp
{
    /** @var MpcReader */
    private $reader;
    
    /** @var MpcWriter */
    private $writer;
    
    /** @var WeeekClient */
    private $weeekClient;
    
    /** @var array */
    private $config;

    /**
     * Конструктор MCP сервера
     * 
     * @param resource $stdin Входной поток
     * @param resource $stdout Выходной поток
     * @param array $config Конфигурация сервера
     */
    public function __construct($stdin, $stdout, array $config = [])
    {
        $this->reader = new MpcReader($stdin);
        $this->writer = new MpcWriter($stdout);
        $this->config = $config;
        
        // Инициализация клиента API Weeek
        $apiToken = $config['auth']['weeek_api_token'] ?? null;
        if (!$apiToken) {
            throw new Exception("API токен Weeek не указан в конфигурации");
        }
        
        // Создаем экземпляр официального клиента Weeek
        $this->weeekClient = new WeeekClient($apiToken);
        
        // Логируем успешную инициализацию
        $this->log("WeeekMcp инициализирован успешно с токеном API");
    }

    /**
     * Запуск MCP сервера
     */
    public function run()
    {
        $this->log("MCP сервер запущен и ожидает сообщения");
        while (true) {
            $message = $this->reader->readMessage();
            if ($message === null) {
                $this->log("Получено null сообщение, завершение работы");
                break;
            }
            
            $this->log("Получено сообщение: " . json_encode($message));
            
            $response = $this->processMessage($message);
            $this->log("Отправляем ответ: " . json_encode($response));
            
            $result = $this->writer->writeMessage($response);
            if (!$result) {
                $this->log("Ошибка при отправке ответа");
            }
        }
    }

    /**
     * Вспомогательный метод для логирования отладочных сообщений
     * 
     * @param string $message Сообщение для логирования
     */
    private function log(string $message)
    {
        $time = date('Y-m-d H:i:s');
        fwrite(STDERR, "[{$time}] {$message}\n");
    }

    /**
     * Обработка входящего сообщения
     * 
     * @param array $message Входящее сообщение
     * @return array Ответ на сообщение
     */
    private function processMessage(array $message)
    {
        $method = $message['method'] ?? '';
        $messageId = $message['id'] ?? null;
        $params = $message['params'] ?? [];
        
        $this->log("Обработка метода: {$method}, id: {$messageId}");
        
        switch ($method) {
            case 'initialize':
                $this->log("Вызов метода initialize");
                return $this->handleInitialize($messageId, $params);
            
            case 'shutdown':
                $this->log("Вызов метода shutdown");
                return $this->handleShutdown($messageId);
            
            case 'discover_tools':
                $this->log("Вызов метода discover_tools");
                return $this->handleDiscoverTools($messageId);
            
            case 'run_tool':
                $toolName = $params['name'] ?? 'неизвестный';
                $this->log("Вызов метода run_tool, инструмент: {$toolName}");
                return $this->handleRunTool($messageId, $params);
            
            default:
                $this->log("Неизвестный метод: {$method}");
                return [
                    'jsonrpc' => '2.0',
                    'id' => $messageId,
                    'error' => [
                        'code' => -32601,
                        'message' => "Метод '{$method}' не найден"
                    ]
                ];
        }
    }

    /**
     * Обработка метода initialize
     * 
     * @param mixed $messageId ID сообщения
     * @param array $params Параметры
     * @return array Ответ
     */
    private function handleInitialize($messageId, array $params)
    {
        $this->log("Инициализация MCP сервера");
        return [
            'jsonrpc' => '2.0',
            'id' => $messageId,
            'result' => [
                'name' => 'weeek-mcp-server',
                'version' => '1.0.0',
                'capabilities' => [
                    'supports_tools' => true
                ]
            ]
        ];
    }
    
    /**
     * Обработка метода shutdown
     * 
     * @param mixed $messageId ID сообщения
     * @return array Ответ
     */
    private function handleShutdown($messageId)
    {
        $this->log("Завершение работы MCP сервера");
        return [
            'jsonrpc' => '2.0',
            'id' => $messageId,
            'result' => null
        ];
    }
    
    /**
     * Обработка метода discover_tools
     * 
     * @param mixed $messageId ID сообщения
     * @return array Ответ
     */
    private function handleDiscoverTools($messageId)
    {
        $this->log("Запрос списка доступных инструментов");
        
        $tools = [
            [
                'name' => 'create_task',
                'description' => 'Создание задачи в проекте Weeek',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'Название задачи'
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Описание задачи'
                        ],
                        'project_id' => [
                            'type' => 'string',
                            'description' => 'ID проекта'
                        ],
                        'due_date' => [
                            'type' => 'string',
                            'description' => 'Срок выполнения задачи в формате YYYY-MM-DD'
                        ],
                        'assignee_ids' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string'
                            ],
                            'description' => 'Список ID исполнителей задачи'
                        ],
                        'tag_ids' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string'
                            ],
                            'description' => 'Список ID тегов'
                        ]
                    ],
                    'required' => ['title', 'project_id']
                ],
                'return' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => [
                            'type' => 'string',
                            'description' => 'ID созданной задачи'
                        ],
                        'status' => [
                            'type' => 'string',
                            'description' => 'Статус операции'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'get_projects',
                'description' => 'Получение списка проектов Weeek',
                'parameters' => [
                    'type' => 'object',
                    'properties' => []
                ],
                'return' => [
                    'type' => 'object',
                    'properties' => [
                        'projects' => [
                            'type' => 'array',
                            'description' => 'Список проектов'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'get_tasks',
                'description' => 'Получение списка задач',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => [
                            'type' => 'string',
                            'description' => 'ID проекта для фильтрации'
                        ]
                    ]
                ],
                'return' => [
                    'type' => 'object',
                    'properties' => [
                        'tasks' => [
                            'type' => 'array',
                            'description' => 'Список задач'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'create_board',
                'description' => 'Создание новой доски в Weeek',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'Название доски'
                        ],
                        'project_id' => [
                            'type' => 'string',
                            'description' => 'ID проекта'
                        ]
                    ],
                    'required' => ['title']
                ],
                'return' => [
                    'type' => 'object',
                    'properties' => [
                        'board_id' => [
                            'type' => 'string',
                            'description' => 'ID созданной доски'
                        ],
                        'status' => [
                            'type' => 'string',
                            'description' => 'Статус операции'
                        ]
                    ]
                ]
            ]
        ];
        
        $this->log("Возвращаем " . count($tools) . " инструментов");
        
        $response = [
            'jsonrpc' => '2.0',
            'id' => $messageId,
            'result' => [
                'tools' => $tools
            ]
        ];
        
        return $response;
    }
    
    /**
     * Обработка метода run_tool
     * 
     * @param mixed $messageId ID сообщения
     * @param array $params Параметры
     * @return array Ответ
     */
    private function handleRunTool($messageId, array $params)
    {
        $toolName = $params['name'] ?? '';
        $toolParams = $params['parameters'] ?? [];
        
        $this->log("Запуск инструмента: {$toolName}, параметры: " . json_encode($toolParams));
        
        switch ($toolName) {
            case 'create_task':
                return $this->handleCreateTask($messageId, $toolParams);
            
            case 'get_projects':
                return $this->handleGetProjects($messageId);
            
            case 'get_tasks':
                return $this->handleGetTasks($messageId, $toolParams);
            
            case 'create_board':
                return $this->handleCreateBoard($messageId, $toolParams);
            
            default:
                $this->log("Неизвестный инструмент: {$toolName}");
                return [
                    'jsonrpc' => '2.0',
                    'id' => $messageId,
                    'error' => [
                        'code' => -32601,
                        'message' => "Инструмент '{$toolName}' не найден"
                    ]
                ];
        }
    }
    
    /**
     * Обработка инструмента create_task
     * 
     * @param mixed $messageId ID сообщения
     * @param array $params Параметры инструмента
     * @return array Ответ
     */
    private function handleCreateTask($messageId, array $params)
    {
        try {
            // Проверка обязательных параметров
            if (empty($params['title'])) {
                throw new Exception("Не указано название задачи");
            }
            
            // Если не указан project_id, используем значение из конфигурации
            $projectId = $params['project_id'] ?? $this->config['default_project_id'] ?? null;
            if (!$projectId) {
                throw new Exception("Не указан ID проекта");
            }
            
            // Параметры для создания задачи
            $taskData = [
                'title' => $params['title'],
                'project_id' => $projectId,
            ];
            
            // Добавляем опциональные параметры, если они указаны
            if (!empty($params['description'])) {
                $taskData['description'] = $params['description'];
            }
            
            if (!empty($params['due_date'])) {
                $taskData['due_date'] = $params['due_date'];
            }
            
            if (!empty($params['assignee_ids'])) {
                $taskData['assignee_ids'] = $params['assignee_ids'];
            }
            
            if (!empty($params['tag_ids'])) {
                $taskData['tag_ids'] = $params['tag_ids'];
            }
            
            $this->log("Создание задачи с данными: " . json_encode($taskData));
            
            // Вызов API для создания задачи
            $response = $this->weeekClient->taskManager->tasks->create($taskData);
            
            $this->log("Задача успешно создана: " . ($response->task->id ?? 'ID неизвестен'));
            
            return [
                'jsonrpc' => '2.0',
                'id' => $messageId,
                'result' => [
                    'task_id' => $response->task->id ?? null,
                    'status' => 'success'
                ]
            ];
        } catch (Exception $e) {
            $this->log("Ошибка при создании задачи: " . $e->getMessage());
            return [
                'jsonrpc' => '2.0',
                'id' => $messageId,
                'error' => [
                    'code' => -32603,
                    'message' => $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * Обработка инструмента get_projects
     * 
     * @param mixed $messageId ID сообщения
     * @return array Ответ
     */
    private function handleGetProjects($messageId)
    {
        try {
            $this->log("Получение списка проектов");
            
            // Получение списка проектов
            $response = $this->weeekClient->taskManager->projects->index();
            
            $this->log("Получено проектов: " . count($response->projects ?? []));
            
            return [
                'jsonrpc' => '2.0',
                'id' => $messageId,
                'result' => [
                    'projects' => $response->projects ?? []
                ]
            ];
        } catch (Exception $e) {
            $this->log("Ошибка при получении списка проектов: " . $e->getMessage());
            return [
                'jsonrpc' => '2.0',
                'id' => $messageId,
                'error' => [
                    'code' => -32603,
                    'message' => $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * Обработка инструмента get_tasks
     * 
     * @param mixed $messageId ID сообщения
     * @param array $params Параметры инструмента
     * @return array Ответ
     */
    private function handleGetTasks($messageId, array $params)
    {
        try {
            $queryParams = [];
            
            // Добавляем фильтрацию по проекту, если указан
            if (!empty($params['project_id'])) {
                $queryParams['project_id'] = $params['project_id'];
                $this->log("Получение списка задач для проекта: " . $params['project_id']);
            } else {
                $this->log("Получение списка всех задач");
            }
            
            // Получение списка задач
            $response = $this->weeekClient->taskManager->tasks->index($queryParams);
            
            $this->log("Получено задач: " . count($response->tasks ?? []));
            
            return [
                'jsonrpc' => '2.0',
                'id' => $messageId,
                'result' => [
                    'tasks' => $response->tasks ?? []
                ]
            ];
        } catch (Exception $e) {
            $this->log("Ошибка при получении списка задач: " . $e->getMessage());
            return [
                'jsonrpc' => '2.0',
                'id' => $messageId,
                'error' => [
                    'code' => -32603,
                    'message' => $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * Обработка инструмента create_board
     * 
     * @param mixed $messageId ID сообщения
     * @param array $params Параметры инструмента
     * @return array Ответ
     */
    private function handleCreateBoard($messageId, array $params)
    {
        try {
            // Проверка обязательных параметров
            if (empty($params['title'])) {
                throw new Exception("Не указано название доски");
            }
            
            // Если не указан project_id, используем значение из конфигурации
            $projectId = $params['project_id'] ?? $this->config['default_project_id'] ?? null;
            if (!$projectId) {
                throw new Exception("Не указан ID проекта");
            }
            
            // Параметры для создания доски
            $boardData = [
                'title' => $params['title'],
                'project_id' => $projectId,
            ];
            
            $this->log("Создание доски с данными: " . json_encode($boardData));
            
            // Вызов API для создания доски
            $response = $this->weeekClient->taskManager->boards->create($boardData);
            
            $this->log("Доска успешно создана: " . ($response->board->id ?? 'ID неизвестен'));
            
            // Обновление кеша досок, если это необходимо
            if (isset($this->config['update_cache_after_create']) && $this->config['update_cache_after_create']) {
                try {
                    // Мы используем механизм кеширования из нашего приложения
                    // Если кеш не инициализирован, просто пропускаем обновление
                    $cacheClass = 'App\\Utils\\Cache';
                    $loaderClass = 'App\\Utils\\CacheLoader';
                    
                    if (class_exists($cacheClass) && class_exists($loaderClass)) {
                        $cache = $cacheClass::getInstance();
                        $loader = new $loaderClass($this->weeekClient, $this->config);
                        
                        if (method_exists($loader, 'loadBoards')) {
                            $loader->loadBoards();
                            $this->log("Кеш досок успешно обновлен");
                        }
                    }
                } catch (Exception $cacheEx) {
                    $this->log("Ошибка при обновлении кеша: " . $cacheEx->getMessage());
                    // Игнорируем ошибки кеширования
                }
            }
            
            return [
                'jsonrpc' => '2.0',
                'id' => $messageId,
                'result' => [
                    'board_id' => $response->board->id ?? null,
                    'status' => 'success'
                ]
            ];
        } catch (Exception $e) {
            $this->log("Ошибка при создании доски: " . $e->getMessage());
            return [
                'jsonrpc' => '2.0',
                'id' => $messageId,
                'error' => [
                    'code' => -32603,
                    'message' => $e->getMessage()
                ]
            ];
        }
    }
} 