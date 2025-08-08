<?php
declare(strict_types=1);

namespace WeeekMcp\Methods\TaskManager;

use WeeekMcp\Utils\Cache;
use WeeekMcp\Utils\CacheLoader;
use Weeek\Client as WeeekClient;

class Boards
{
    public function __construct(
        public WeeekClient $weeekClient,
        public CacheLoader $cacheLoader,
        public Cache $cache,
        public ?int $projectId,
    ) {
        $this->cache = Cache::getInstance();
    }

    public function forceReload(): bool
    {
        return $this->cacheLoader->loadBoards();
    }
    
    /**
     * Возвращает кешированные доски в формате ['lastUpdated' => int, 'data' => list<array>]
     */
    public function all(): array
    {
        $boards = $this->cache->get('boards', ['lastUpdated' => 0, 'data' => []]);
        if (empty($boards['data'])) {
            $this->cacheLoader->loadBoards();
            $boards = $this->cache->get('boards', ['lastUpdated' => 0, 'data' => []]);
        }
        return $boards;
    }
    
    /**
     * Возвращает список досок по проекту
     * 
     * @return array<int, array{id:int,title:string,project_id:int}>
     */
    public function getByProjectId(int $projectId): array
    {
        $allBoards = $this->all();
        $boards = [];
        foreach ($allBoards['data'] as $board) {
            if ((int)$board['project_id'] === $projectId) {
                $boards[] = $board;
            }
        }
        return $boards;
    }

    /**
     * Возвращает доску по ID
     */
    public function get(int $id): ?array
    {
        $boards = $this->all();
        foreach ($boards['data'] as $board) {
            if ((int)$board['id'] === $id) {
                return $board;  
            }
        }
        return null;
    }

    /**
     * Создает доску через API и обновляет кеш
     */
    public function create(array $data): object
    {
        $response = $this->weeekClient->taskManager->boards->create($data);
        $this->cacheLoader->loadBoards();
        return $response;
    }
}