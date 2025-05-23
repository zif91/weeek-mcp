<?php

namespace WeeekMcp\Api;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Клиент для работы с API Weeek
 */
class WeeekApiClient
{
    /** @var string */
    private $apiToken;
    
    /** @var Client */
    private $httpClient;
    
    /** @var string */
    private $apiBaseUrl = 'https://api.weeek.net/public/v1';
    
    /** @var string */
    private $timezone;

    /**
     * Конструктор
     * 
     * @param string $apiToken Токен API Weeek
     * @param string $timezone Часовой пояс
     */
    public function __construct(string $apiToken, string $timezone = 'UTC')
    {
        $this->apiToken = $apiToken;
        $this->timezone = $timezone;
        
        $this->httpClient = new Client([
            'base_uri' => $this->apiBaseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Создание задачи
     * 
     * @param array $taskData Данные задачи
     * @return array Результат операции
     * @throws Exception
     */
    public function createTask(array $taskData): array
    {
        try {
            $response = $this->httpClient->post('/task-manager/task', [
                'json' => $taskData,
                'headers' => [
                    'Timezone' => $this->timezone
                ]
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);
            
            if (!$result || !isset($result['data'])) {
                throw new Exception("Ошибка при создании задачи: неверный формат ответа");
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new Exception("Ошибка при создании задачи: " . $e->getMessage());
        }
    }
    
    /**
     * Получение списка проектов
     * 
     * @return array Список проектов
     * @throws Exception
     */
    public function getProjects(): array
    {
        try {
            $response = $this->httpClient->get('/task-manager/project', [
                'headers' => [
                    'Timezone' => $this->timezone
                ]
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);
            
            if (!$result || !isset($result['data'])) {
                throw new Exception("Ошибка при получении списка проектов: неверный формат ответа");
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new Exception("Ошибка при получении списка проектов: " . $e->getMessage());
        }
    }
    
    /**
     * Получение информации о проекте
     * 
     * @param string $projectId ID проекта
     * @return array Информация о проекте
     * @throws Exception
     */
    public function getProject(string $projectId): array
    {
        try {
            $response = $this->httpClient->get("/task-manager/project/{$projectId}", [
                'headers' => [
                    'Timezone' => $this->timezone
                ]
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);
            
            if (!$result || !isset($result['data'])) {
                throw new Exception("Ошибка при получении информации о проекте: неверный формат ответа");
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new Exception("Ошибка при получении информации о проекте: " . $e->getMessage());
        }
    }
    
    /**
     * Получение списка задач
     * 
     * @param array $params Параметры запроса
     * @return array Список задач
     * @throws Exception
     */
    public function getTasks(array $params = []): array
    {
        try {
            $response = $this->httpClient->get('/task-manager/task', [
                'query' => $params,
                'headers' => [
                    'Timezone' => $this->timezone
                ]
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);
            
            if (!$result || !isset($result['data'])) {
                throw new Exception("Ошибка при получении списка задач: неверный формат ответа");
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new Exception("Ошибка при получении списка задач: " . $e->getMessage());
        }
    }
    
    /**
     * Получение информации о задаче
     * 
     * @param string $taskId ID задачи
     * @return array Информация о задаче
     * @throws Exception
     */
    public function getTask(string $taskId): array
    {
        try {
            $response = $this->httpClient->get("/task-manager/task/{$taskId}", [
                'headers' => [
                    'Timezone' => $this->timezone
                ]
            ]);
            
            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);
            
            if (!$result || !isset($result['data'])) {
                throw new Exception("Ошибка при получении информации о задаче: неверный формат ответа");
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new Exception("Ошибка при получении информации о задаче: " . $e->getMessage());
        }
    }
    
    /**
     * Установка часового пояса
     * 
     * @param string $timezone Часовой пояс
     * @return void
     */
    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }
} 