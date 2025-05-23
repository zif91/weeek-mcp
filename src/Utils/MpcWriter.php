<?php

namespace WeeekMcp\Utils;

/**
 * Класс для записи сообщений MPC протокола
 */
class MpcWriter
{
    /** @var resource */
    private $outputStream;

    /**
     * Конструктор
     * 
     * @param resource $outputStream Выходной поток
     */
    public function __construct($outputStream)
    {
        $this->outputStream = $outputStream;
    }

    /**
     * Запись сообщения в выходной поток
     * 
     * @param array $message Сообщение для записи
     * @return bool Результат операции
     */
    public function writeMessage(array $message): bool
    {
        // Кодируем сообщение в JSON
        $content = json_encode($message);
        $contentLength = strlen($content);
        
        // Формируем заголовки
        $headers = "Content-Length: {$contentLength}\r\n";
        $headers .= "Content-Type: application/json\r\n";
        $headers .= "\r\n";
        
        // Записываем заголовки и содержимое
        $bytesWritten = fwrite($this->outputStream, $headers);
        if ($bytesWritten === false) {
            return false;
        }
        
        $bytesWritten = fwrite($this->outputStream, $content);
        if ($bytesWritten === false) {
            return false;
        }
        
        fflush($this->outputStream);
        
        return true;
    }
} 