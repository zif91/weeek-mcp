<?php

namespace WeeekMcp\Utils;

/**
 * Простой MCP клиент для тестирования
 */
class McpClient
{
    /**
     * URL сервера MCP
     * @var string
     */
    private $serverUrl;
    
    /**
     * Название сервера
     * @var string
     */
    private $serverName;
    
    /**
     * Режим отладки
     * @var bool
     */
    private $debug;
    
    /**
     * Конструктор
     * 
     * @param string $serverUrl URL сервера MCP
     * @param string $serverName Название сервера
     * @param bool $debug Включить режим отладки
     */
    public function __construct(string $serverUrl = 'http://localhost:3000', string $serverName = 'weeek-mcp-server', bool $debug = false)
    {
        $this->serverUrl = $serverUrl;
        $this->serverName = $serverName;
        $this->debug = $debug;
    }
    
    /**
     * Вызов метода MCP
     * 
     * @param string $method Название метода
     * @param array $params Параметры
     * @return mixed Результат выполнения
     * @throws \Exception В случае ошибки
     */
    public function call(string $method, array $params = [])
    {
        $data = [
            'method' => $method,
            'params' => $params
        ];
        
        if ($this->debug) {
            echo "DEBUG: Запрос к {$this->serverUrl}\n";
            echo "DEBUG: Метод: {$method}\n";
            echo "DEBUG: Параметры: " . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
        
        $ch = curl_init($this->serverUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($this->debug) {
            echo "DEBUG: HTTP код: {$httpCode}\n";
            if ($error) {
                echo "DEBUG: Ошибка curl: {$error}\n";
            }
            echo "DEBUG: Ответ: " . $response . "\n";
        }
        
        curl_close($ch);
        
        if ($httpCode != 200) {
            throw new \Exception("HTTP ошибка: {$httpCode}. " . ($error ? "curl ошибка: {$error}" : ""));
        }
        
        if (!$response) {
            throw new \Exception("Пустой ответ от сервера");
        }
        
        $jsonResult = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Ошибка декодирования JSON: " . json_last_error_msg() . ". Ответ: " . $response);
        }
        
        if (isset($jsonResult['status']) && $jsonResult['status'] === 'error') {
            throw new \Exception("Ошибка API: " . ($jsonResult['error'] ?? 'Неизвестная ошибка'));
        }
        
        return $jsonResult;
    }
    
    /**
     * Получение списка проектов
     * 
     * @return array Список проектов
     */
    public function getProjects()
    {
        return $this->call('get_projects');
    }
    
    /**
     * Получение списка задач
     * 
     * @param string|null $projectId ID проекта (опционально)
     * @return array Список задач
     */
    public function getTasks($projectId = null)
    {
        $params = [];
        if ($projectId) {
            $params['project_id'] = $projectId;
        }
        
        return $this->call('get_tasks', $params);
    }
    
    /**
     * Получение списка досок
     * 
     * @param string|null $projectId ID проекта (опционально)
     * @return array Список досок
     */
    public function getBoards($projectId = null)
    {
        $params = [];
        if ($projectId) {
            $params['project_id'] = $projectId;
        }
        
        return $this->call('get_boards', $params);
    }
    
    /**
     * Получение списка тегов
     * 
     * @return array Список тегов
     */
    public function getTags()
    {
        return $this->call('get_tags');
    }
    
    /**
     * Создание задачи
     * 
     * @param string $title Название задачи
     * @param string|null $description Описание задачи (опционально)
     * @param string|null $projectId ID проекта (опционально)
     * @return array Информация о созданной задаче
     */
    public function createTask($title, $description = null, $projectId = null)
    {
        $params = [
            'title' => $title
        ];
        
        if ($description) {
            $params['description'] = $description;
        }
        
        if ($projectId) {
            $params['project_id'] = $projectId;
        }
        
        return $this->call('create_task', $params);
    }
    
    /**
     * Создание задачи с тегом
     * 
     * @param string $title Название задачи
     * @param string $tagId ID тега
     * @param string|null $description Описание задачи (опционально)
     * @param string|null $projectId ID проекта (опционально)
     * @param string|null $boardId ID доски (опционально)
     * @return array Информация о созданной задаче
     */
    public function createTaskWithTag($title, $tagId, $description = null, $projectId = null, $boardId = null)
    {
        $params = [
            'title' => $title,
            'tag_id' => $tagId
        ];
        
        if ($description) {
            $params['description'] = $description;
        }
        
        if ($projectId) {
            $params['project_id'] = $projectId;
        }
        
        if ($boardId) {
            $params['board_id'] = $boardId;
        }
        
        return $this->call('create_task_with_tag', $params);
    }
    
    /**
     * Прикрепление тега к задаче
     * 
     * @param string $taskId ID задачи
     * @param string $tagId ID тега
     * @return array Результат операции
     */
    public function attachTagToTask($taskId, $tagId)
    {
        $params = [
            'task_id' => $taskId,
            'tag_id' => $tagId
        ];
        
        return $this->call('attach_tag_to_task', $params);
    }
    
    /**
     * Создание доски
     * 
     * @param string $title Название доски
     * @param string|null $projectId ID проекта (опционально)
     * @return array Информация о созданной доске
     */
    public function createBoard($title, $projectId = null)
    {
        $params = [
            'title' => $title
        ];
        
        if ($projectId) {
            $params['project_id'] = $projectId;
        }
        
        return $this->call('create_board', $params);
    }
    
    /**
     * Обновление доски
     * 
     * @param string $boardId ID доски
     * @param string $title Новое название доски
     * @return array Результат операции
     */
    public function updateBoard($boardId, $title)
    {
        $params = [
            'board_id' => $boardId,
            'title' => $title
        ];
        
        return $this->call('update_board', $params);
    }
    
    /**
     * Удаление доски
     * 
     * @param string $boardId ID доски
     * @return array Результат операции
     */
    public function deleteBoard($boardId)
    {
        $params = [
            'board_id' => $boardId
        ];
        
        return $this->call('delete_board', $params);
    }
    
    /**
     * Создание тега
     * 
     * @param string $title Название тега
     * @param string|null $color Цвет тега (опционально)
     * @return array Информация о созданном теге
     */
    public function createTag($title, $color = null)
    {
        $params = [
            'title' => $title
        ];
        
        if ($color) {
            $params['color'] = $color;
        }
        
        return $this->call('create_tag', $params);
    }
    
    /**
     * Обновление тега
     * 
     * @param string $tagId ID тега
     * @param string|null $title Новое название тега (опционально)
     * @param string|null $color Новый цвет тега (опционально)
     * @return array Результат операции
     */
    public function updateTag($tagId, $title = null, $color = null)
    {
        $params = [
            'tag_id' => $tagId
        ];
        
        if ($title) {
            $params['title'] = $title;
        }
        
        if ($color) {
            $params['color'] = $color;
        }
        
        return $this->call('update_tag', $params);
    }
    
    /**
     * Удаление тега
     * 
     * @param string $tagId ID тега
     * @return array Результат операции
     */
    public function deleteTag($tagId)
    {
        $params = [
            'tag_id' => $tagId
        ];
        
        return $this->call('delete_tag', $params);
    }
} 