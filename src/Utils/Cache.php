<?php

namespace WeeekMcp\Utils;

class Cache
{
    private static $instance;
    private $cachePath;
    private $data = [];

    private function __construct()
    {
        $this->cachePath = __DIR__ . '/../../cache';
        
        // Создаем директорию для кеша, если она не существует
        if (!file_exists($this->cachePath)) {
            mkdir($this->cachePath, 0777, true);
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function set($key, $value)
    {
        $this->data[$key] = $value;
        $this->saveToFile($key);
        return $this;
    }

    public function get($key, $default = null)
    {
        // if (!isset($this->data[$key])) {
            $this->loadFromFile($key);
        // }
        
        return $this->data[$key] ?? $default;
    }

    public function has($key)
    {
        if (!isset($this->data[$key])) {
            $this->loadFromFile($key);
        }
        
        return isset($this->data[$key]);
    }

    private function saveToFile($key)
    {
        if (!isset($this->data[$key])) {
            return;
        }
        
        $filePath = $this->getFilePath($key);
        // unlink($filePath);
        file_put_contents($filePath, json_encode($this->data[$key]));
    }

    private function loadFromFile($key)
    {
        $filePath = $this->getFilePath($key);
        
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $this->data[$key] = json_decode($content, true);
        }
    }

    private function getFilePath($key)
    {
        return $this->cachePath . '/' . $key . '.json';
    }

    public function saveAll()
    {
        foreach (array_keys($this->data) as $key) {
            $this->saveToFile($key);
        }
    }
    
    /**
     * Удаляет элемент кеша по ключу
     * 
     * @param string $key Ключ кеша
     * @return bool Успешность операции
     */
    public function delete($key)
    {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
        }
        
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        
        return true;
    }
    
    /**
     * Очищает весь кеш
     * 
     * @return bool Успешность операции
     */
    public function clear()
    {
        $this->data = [];
        
        $files = glob($this->cachePath . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
        
        return true;
    }
} 