<?php

namespace WeeekMcp\Methods\TaskManager;

use WeeekMcp\Utils\Cache;
use WeeekMcp\Utils\CacheLoader;
use Weeek\Client as WeeekClient;

class Boards
{
    public function __construct(public WeeekClient $weeekClient, public CacheLoader $cacheLoader, public Cache $cache, public $projectId)
    {
        $this->cache = Cache::getInstance();
    }

    public function forceReload()
    {
        $this->cacheLoader->loadBoards();
    }
    
    public function all()
    {
        $boards = $this->cache->get('boards', ['data' => []]);
        if (empty($boards['data'])) {
            $boards = $this->cacheLoader->loadBoards();
        }
        return $boards;
    }
    

    // get by project id
    public function getByProjectId($projectId)
    {
        $allBoards = $this->all();
        $boards = [];
        foreach ($allBoards['data'] as $board) {
            if ($board['project_id'] == $projectId) {
                $boards[] = $board;
            }
        }
        return $boards;
    }

    public function get($id)
    {
        $boards = $this->all();
        foreach ($boards as $board) {
            if ($board['id'] == $id) {
                return $board;  
            }
        }
        return null;
    }

    public function create($data)
    {
        $response = $this->weeekClient->taskManager->boards->create($data);
        $this->cacheLoader->loadAllData();
        return $response;
    }
    
}