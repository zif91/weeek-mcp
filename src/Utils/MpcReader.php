<?php

namespace WeeekMcp\Utils;

use Exception;

/**
 * Класс для чтения сообщений MPC протокола
 */
class MpcReader
{
    /** @var resource */
    private $inputStream;

    /**
     * Конструктор
     * 
     * @param resource $inputStream Входной поток
     */
    public function __construct($inputStream)
    {
        $this->inputStream = $inputStream;
    }

    /**
     * Чтение сообщения из входного потока
     * 
     * @return array|null Прочитанное сообщение или null в случае ошибки
     */
    public function readMessage()
    {
        // Читаем заголовки
        $headers = [];
        $line = $this->readLine();
        
        if ($line === null) {
            return null;
        }
        
        while ($line !== '') {
            if ($line === false) {
                return null;
            }
            
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
            
            $line = $this->readLine();
        }
        
        // Читаем содержимое сообщения
        $contentLength = (int) ($headers['Content-Length'] ?? 0);
        if ($contentLength <= 0) {
            return null;
        }
        
        $content = $this->readContent($contentLength);
        if ($content === null) {
            return null;
        }
        
        // Декодируем JSON
        try {
            $message = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            
            return $message;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Чтение строки из входного потока
     * 
     * @return string|null Прочитанная строка или null в случае ошибки
     */
    private function readLine()
    {
        $line = fgets($this->inputStream);
        
        if ($line === false) {
            return null;
        }
        
        return rtrim($line, "\r\n");
    }

    /**
     * Чтение содержимого сообщения
     * 
     * @param int $length Длина содержимого
     * @return string|null Прочитанное содержимое или null в случае ошибки
     */
    private function readContent(int $length)
    {
        $content = '';
        $bytesRead = 0;
        
        while ($bytesRead < $length) {
            $chunk = fread($this->inputStream, $length - $bytesRead);
            
            if ($chunk === false) {
                return null;
            }
            
            $content .= $chunk;
            $bytesRead += strlen($chunk);
        }
        
        return $content;
    }
} 