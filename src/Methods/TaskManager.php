<?php
declare(strict_types=1);

namespace WeeekMcp\Methods;

use Weeek\Client as WeeekClient;
use WeeekMcp\Utils\Cache;
use WeeekMcp\Utils\CacheLoader;
use WeeekMcp\Methods\TaskManager\Boards;
use WeeekMcp\Methods\TaskManager\Tasks;

class TaskManager
{
    public Tasks $tasks;
    public Boards $boards;

    public function __construct(
        public WeeekClient $weeekClient,
        public CacheLoader $cacheLoader,
        public Cache $cache,
        public ?int $projectId = null,
    ) {
        $this->boards = new Boards($this->weeekClient, $this->cacheLoader, $this->cache, $this->projectId);
        $this->tasks = new Tasks($this, $this->projectId, null);
    }
}