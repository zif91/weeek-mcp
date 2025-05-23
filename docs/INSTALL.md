# Руководство по установке и настройке Weeek MCP Server

## Оглавление

- [Системные требования](#системные-требования)
- [Установка](#установка)
- [Конфигурация](#конфигурация)
- [Настройка Claude Desktop](#настройка-claude-desktop)
- [Настройка других MCP клиентов](#настройка-других-mcp-клиентов)
- [Первый запуск и тестирование](#первый-запуск-и-тестирование)
- [Устранение неполадок](#устранение-неполадок)
- [Обновление](#обновление)

## Системные требования

### Минимальные требования

- **PHP**: 8.0 или выше
- **Composer**: 2.0 или выше
- **Память**: 128 MB RAM
- **Диск**: 50 MB свободного места
- **ОС**: Linux, macOS, Windows

### Рекомендуемые требования

- **PHP**: 8.1 или выше с расширениями:
  - `json`
  - `curl`
  - `mbstring`
  - `openssl`
- **Память**: 256 MB RAM
- **Диск**: 100 MB свободного места

### Проверка совместимости

Выполните команду для проверки версии PHP:

```bash
php --version
```

Убедитесь, что установлен Composer:

```bash
composer --version
```

## Установка

### Способ 1: Клонирование из Git (рекомендуемый)

```bash
# Клонируйте репозиторий
git clone https://github.com/streeboga/weeek-mcp.git

# Перейдите в директорию проекта
cd weeek-mcp

# Установите зависимости
composer install --no-dev --optimize-autoloader
```

### Способ 2: Скачивание архива

1. Скачайте последний релиз с GitHub
2. Распакуйте архив в желаемую директорию
3. Установите зависимости:

```bash
cd weeek-mcp
composer install --no-dev --optimize-autoloader
```

### Проверка установки

Выполните команду для проверки установки:

```bash
php server.php --version
```

Если команда выполнилась без ошибок, установка прошла успешно.

## Конфигурация

### Основная конфигурация

1. **Создайте файл конфигурации:**

```bash
cp config.json.example config.json
```

2. **Отредактируйте config.json:**

```json
{
    "auth": {
        "weeek_api_token": "YOUR_WEEEK_API_TOKEN"
    },
    "default_project_id": "123456",
    "default_board_id": "789012",
    "default_user_id": "999",
    "timezone": "Europe/Moscow",
    "update_cache_after_create": true
}
```

### Получение API токена Weeek

1. Войдите в свой аккаунт Weeek
2. Перейдите в настройки профиля
3. Найдите раздел "API токены" или "Интеграции"
4. Создайте новый токен с необходимыми правами
5. Скопируйте токен в файл конфигурации

### Получение ID сущностей

#### ID проекта по умолчанию

```bash
# Запустите сервер временно для получения списка проектов
php server.php &
SERVER_PID=$!

# Используйте MCP клиент или создайте тестовый скрипт
php -r "
require 'vendor/autoload.php';
\$client = new Weeek\Client('YOUR_TOKEN');
\$projects = \$client->taskManager->projects->getAll();
foreach (\$projects->projects as \$project) {
    echo \"ID: {\$project->id}, Name: {\$project->name}\n\";
}
"

# Остановите временный сервер
kill $SERVER_PID
```

#### ID доски по умолчанию

Аналогично получите список досок для выбранного проекта.

### Конфигурация через переменные окружения

Альтернативно можно использовать переменные окружения:

```bash
# В ~/.bashrc или ~/.zshrc
export WEEEK_API_TOKEN="your_token_here"
export WEEEK_PROJECT_ID="123456"
export WEEEK_BOARD_ID="789012"
export WEEEK_USER_ID="999"

# Перезагрузите оболочку
source ~/.bashrc
```

### Конфигурация логирования

Для включения подробного логирования:

```bash
export DEBUG=1
export LOG_LEVEL=debug
```

## Настройка Claude Desktop

### macOS

1. **Найдите файл конфигурации Claude Desktop:**

```bash
~/Library/Application Support/Claude/claude_desktop_config.json
```

2. **Отредактируйте конфигурацию:**

```json
{
  "mcpServers": {
    "weeek": {
      "command": "php",
      "args": ["/full/path/to/weeek-mcp/server.php"],
      "env": {
        "WEEEK_API_TOKEN": "your_token_here"
      }
    }
  }
}
```

### Windows

1. **Найдите файл конфигурации:**

```
%APPDATA%\Claude\claude_desktop_config.json
```

2. **Отредактируйте конфигурацию:**

```json
{
  "mcpServers": {
    "weeek": {
      "command": "php",
      "args": ["C:\\path\\to\\weeek-mcp\\server.php"],
      "env": {
        "WEEEK_API_TOKEN": "your_token_here"
      }
    }
  }
}
```

### Linux

1. **Найдите файл конфигурации:**

```bash
~/.config/Claude/claude_desktop_config.json
```

2. **Конфигурация аналогична macOS**

### Проверка подключения в Claude Desktop

1. Перезапустите Claude Desktop
2. Создайте новый чат
3. Попробуйте выполнить команду:

```
Покажи мои проекты в Weeek
```

Если настройка выполнена правильно, вы увидите список ваших проектов.

## Настройка других MCP клиентов

### Cursor IDE

Добавьте в файл конфигурации Cursor:

```json
{
  "mcp": {
    "servers": {
      "weeek": {
        "command": "php",
        "args": ["/path/to/weeek-mcp/server.php"]
      }
    }
  }
}
```

### VS Code с расширением MCP

Установите расширение MCP для VS Code и добавьте сервер:

```json
{
  "mcp.servers": [
    {
      "name": "weeek",
      "command": "php /path/to/weeek-mcp/server.php"
    }
  ]
}
```

### Собственный MCP клиент

Для разработки собственного клиента используйте библиотеку MCP для вашего языка программирования:

```php
<?php
// Пример PHP клиента
require 'vendor/autoload.php';

use Pronskiy\Mcp\Client;

$client = new Client();
$client->connect('php /path/to/weeek-mcp/server.php');

$result = $client->callTool('get_projects');
echo json_encode($result, JSON_PRETTY_PRINT);
```

## Первый запуск и тестирование

### Тест 1: Запуск сервера

```bash
php server.php
```

Сервер должен запуститься без ошибок и ждать MCP сообщений.

### Тест 2: Проверка подключения к Weeek API

```bash
php test_get_tasks.php
```

Скрипт должен вернуть список задач или информацию об их отсутствии.

### Тест 3: Создание тестовой задачи

```bash
php test_create_task.php
```

### Тест 4: Обновление кэша

```bash
php test_update_cache.php
```

### Тест 5: Интеграция с Claude Desktop

1. Убедитесь, что Claude Desktop настроен правильно
2. Откройте новый чат
3. Выполните команду:

```
Создай задачу "Тестовая задача" с описанием "Проверка интеграции"
```

## Устранение неполадок

### Проблема: Ошибка "Class not found"

**Причина:** Не установлены зависимости или неправильный autoloader

**Решение:**
```bash
composer install --no-dev
composer dump-autoload
```

### Проблема: "Invalid API token"

**Причина:** Неправильный или отсутствующий API токен

**Решение:**
1. Проверьте токен в config.json
2. Убедитесь, что токен действительный
3. Проверьте права токена в Weeek

### Проблема: "Permission denied" при записи кэша

**Причина:** Недостаточно прав для записи в папку cache/

**Решение:**
```bash
chmod 755 cache/
chmod 644 cache/*.json
```

### Проблема: Claude Desktop не видит сервер

**Причина:** Неправильный путь в конфигурации

**Решение:**
1. Используйте абсолютный путь к server.php
2. Проверьте права выполнения:
```bash
chmod +x server.php
```

### Проблема: Медленная работа

**Причина:** Проблемы с кэшем или сетью

**Решение:**
1. Очистите кэш:
```bash
rm -rf cache/*.json
```
2. Перезапустите сервер
3. Проверьте скорость интернета

### Проблема: Ошибки в логах

**Включите подробное логирование:**
```bash
export DEBUG=1
php server.php 2>&1 | tee debug.log
```

### Проблема: Сервер зависает

**Причина:** Бесконечные циклы или заблокированные запросы

**Решение:**
1. Установите тайм-ауты:
```json
{
  "timeout": 30,
  "retry_attempts": 3
}
```
2. Перезапустите сервер

## Обновление

### Обновление через Git

```bash
# Сохраните конфигурацию
cp config.json config.json.backup

# Получите обновления
git pull origin main

# Обновите зависимости
composer install --no-dev --optimize-autoloader

# Восстановите конфигурацию
cp config.json.backup config.json

# Очистите кэш
rm -rf cache/*.json
```

### Обновление через архив

1. Скачайте новую версию
2. Сохраните файлы config.json и cache/
3. Замените файлы проекта
4. Восстановите конфигурацию и кэш
5. Выполните `composer install`

### Проверка обновления

```bash
# Проверьте версию
php server.php --version

# Запустите тесты
php test_get_tasks.php
```

## Автоматический запуск (systemd)

Для автоматического запуска на Linux создайте systemd сервис:

### Создание сервиса

```bash
sudo nano /etc/systemd/system/weeek-mcp.service
```

```ini
[Unit]
Description=Weeek MCP Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/weeek-mcp
ExecStart=/usr/bin/php /path/to/weeek-mcp/server.php
Restart=always
RestartSec=10
Environment=WEEEK_API_TOKEN=your_token_here

[Install]
WantedBy=multi-user.target
```

### Запуск сервиса

```bash
sudo systemctl daemon-reload
sudo systemctl enable weeek-mcp
sudo systemctl start weeek-mcp
sudo systemctl status weeek-mcp
```

## Безопасность

### Защита API токена

1. Не коммитьте config.json в Git
2. Используйте переменные окружения для чувствительных данных
3. Ограничьте права доступа к файлам:

```bash
chmod 600 config.json
```

### Права файлов

```bash
chmod 755 .
chmod 644 *.php
chmod 600 config.json
chmod 755 cache/
chmod 644 cache/*.json
```

### Сетевая безопасность

Если используете сетевое подключение:

1. Используйте HTTPS
2. Настройте файрвол
3. Ограничьте доступ по IP

## Мониторинг

### Логирование

Логи записываются в:
- `server_log.txt` — Основные логи сервера
- `client_log.txt` — Логи клиентских соединений
- STDERR — Отладочная информация

### Мониторинг производительности

```bash
# Мониторинг процесса
ps aux | grep "server.php"

# Мониторинг памяти
top -p $(pgrep -f "server.php")

# Мониторинг файлов
watch -n 5 "ls -la cache/"
```

---

**Поздравляем!** Weeek MCP Server настроен и готов к использованию. При возникновении проблем обращайтесь к разделу устранения неполадок или создавайте issue в репозитории проекта. 