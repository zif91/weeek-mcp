<?php

namespace WeeekMcp\Methods;

use Weeek\Client as WeeekClient;
use WeeekMcp\Utils\Cache;
use WeeekMcp\Utils\CacheLoader;
use WeeekMcp\Methods\TaskManager\Boards;
use WeeekMcp\Methods\TaskManager\Tasks;

class TaskManager
{
    public ?Tasks $tasks = null;
    
    public function __construct(public WeeekClient $weeekClient, public CacheLoader $cacheLoader, public Cache $cache, public $projectId = null, public ?Boards $boards = null)
    {
        $configFile = __DIR__ . '/../../config.json';
//        var_dump($configFile);
//        die;
        $config = json_decode(file_get_contents($configFile), true);


// Создание клиента Weeek API
        $apiToken = $config['auth']['weeek_api_token'];
        $this->weeekClient =  new WeeekClient($apiToken);
        $this->cacheLoader = new CacheLoader($weeekClient, $config);

        $this->cache = Cache::getInstance();
        $this->boards = new Boards($this->weeekClient, $this->cacheLoader, $this->cache, $this->projectId);
        $this->tasks = new Tasks($this, $this->projectId, null);
    }
}