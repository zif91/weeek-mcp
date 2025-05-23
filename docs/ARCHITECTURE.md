# Архитектура Weeek MCP Server

## Обзор

Weeek MCP Server построен по модульной архитектуре с четким разделением ответственности между компонентами. Система использует паттерн MVC с дополнительными слоями для кэширования и коммуникации.

## Диаграмма архитектуры

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   MCP Client    │◄──►│  Weeek MCP      │◄──►│   Weeek API     │
│  (Claude, etc)  │    │     Server      │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                              │
                              ▼
                       ┌─────────────────┐
                       │  Local Cache    │
                       │   (JSON Files)  │
                       └─────────────────┘
```

## Структура проекта

```
weeek-mcp/
├── src/                          # Исходный код
│   ├── WeeekMcp.php             # Основной MCP сервер
│   ├── Server.php               # Альтернативный сервер (Pronskiy MCP)
│   ├── Methods/                 # Бизнес-логика
│   │   └── TaskManager/
│   │       ├── Tasks.php        # Управление задачами
│   │       └── Boards.php       # Управление досками
│   ├── Utils/                   # Утилиты
│   │   ├── Cache.php           # Система кэширования
│   │   ├── CacheLoader.php     # Загрузка данных в кэш
│   │   ├── MpcReader.php       # Чтение MCP сообщений
│   │   └── MpcWriter.php       # Запись MCP сообщений
│   └── Api/                     # API интерфейсы
│       └── TasksApi.php         # API для задач
├── cache/                       # Кэш файлы
├── vendor/                      # Зависимости Composer
├── server.php                   # Точка входа (основной сервер)
├── config.json                  # Конфигурация
└── test_*.php                   # Тестовые скрипты
```

## Основные компоненты

### 1. MCP Server Layer

#### WeeekMcp.php
**Назначение:** Основной класс MCP сервера, обрабатывающий протокол JSON-RPC 2.0

**Основные методы:**
- `run()` — Главный цикл сервера
- `processMessage()` — Обработка входящих сообщений
- `handleInitialize()` — Инициализация соединения
- `handleDiscoverTools()` — Предоставление списка инструментов
- `handleRunTool()` — Выполнение инструментов

**Поток данных:**
```
MCP Client → WeeekMcp::run() → processMessage() → handleRunTool() → Tool Handler
```

#### Server.php
**Назначение:** Альтернативная реализация на базе библиотеки Pronskiy MCP

**Преимущества:**
- Более высокий уровень абстракции
- Встроенная валидация
- Автоматическое управление инструментами

### 2. Business Logic Layer

#### TaskManager.php
**Назначение:** Центральный компонент для управления задачами

**Архитектурные особенности:**
- Dependency Injection для WeeekClient, Cache, CacheLoader
- Делегирование операций специализированным классам
- Unified interface для всех операций с задачами

```php
class TaskManager 
{
    private WeeekClient $weeekClient;
    private Cache $cache;
    private CacheLoader $cacheLoader;
    private Tasks $tasks;
    private Boards $boards;
    
    public function __construct(...) {
        $this->tasks = new Tasks($this, $projectId, $boardId);
        $this->boards = new Boards($this, $projectId);
    }
}
```

#### Tasks.php
**Назначение:** Реализация операций с задачами

**Основные методы:**
- `getAll()` — Получение списка задач с кэшированием
- `create()` — Создание новой задачи
- `update()` — Обновление задачи
- `complete()` / `unComplete()` — Управление статусом
- `delete()` — Удаление задачи

**Паттерны:**
- **Repository Pattern** — Абстракция доступа к данным
- **Cache-Aside** — Кэширование с обновлением при изменении

#### Boards.php
**Назначение:** Управление досками и их колонками

### 3. Data Access Layer

#### Cache.php
**Назначение:** Файловая система кэширования (Singleton)

**Архитектурные решения:**
- Singleton pattern для глобального доступа
- Lazy loading файлов кэша
- Автоматическая сериализация/десериализация JSON

```php
class Cache 
{
    private static $instance;
    private $cachePath;
    private $data = [];
    
    public function set($key, $value) {
        $this->data[$key] = $value;
        $this->saveToFile($key);
    }
    
    public function get($key, $default = null) {
        if (!isset($this->data[$key])) {
            $this->loadFromFile($key);
        }
        return $this->data[$key] ?? $default;
    }
}
```

#### CacheLoader.php
**Назначение:** Загрузка и обновление данных в кэше

**Стратегии загрузки:**
- **Eager Loading** — Загрузка всех данных при старте
- **Selective Refresh** — Обновление конкретных типов данных
- **Error Tolerance** — Продолжение работы при ошибках загрузки

### 4. Communication Layer

#### MpcReader.php / MpcWriter.php
**Назначение:** Низкоуровневая работа с MCP протоколом

**Особенности:**
- Streaming JSON parsing
- Error handling на уровне протокола
- Buffered I/O для производительности

## Паттерны проектирования

### 1. Singleton (Cache)
Обеспечивает единственный экземпляр кэша во всем приложении.

### 2. Dependency Injection
Все зависимости внедряются через конструкторы, что облегчает тестирование и поддержку.

### 3. Repository Pattern
Классы Tasks и Boards абстрагируют доступ к данным.

### 4. Strategy Pattern
Различные стратегии кэширования и обработки ошибок.

### 5. Command Pattern
Каждый MCP инструмент — отдельная команда с валидацией и выполнением.

## Протокол взаимодействия

### MCP Protocol Flow

```
1. Client → Server: initialize
2. Server → Client: capabilities
3. Client → Server: discover_tools
4. Server → Client: tool_list
5. Client → Server: run_tool(name, params)
6. Server → Client: result
```

### Структура MCP сообщений

#### Запрос выполнения инструмента
```json
{
  "jsonrpc": "2.0",
  "id": "req_123",
  "method": "run_tool",
  "params": {
    "name": "create_task",
    "arguments": {
      "title": "New Task",
      "description": "Task description"
    }
  }
}
```

#### Ответ с результатом
```json
{
  "jsonrpc": "2.0",
  "id": "req_123",
  "result": {
    "status": "success",
    "task": {
      "id": "12345",
      "title": "New Task"
    }
  }
}
```

## Система кэширования

### Архитектура кэша

```
Cache Manager (Singleton)
├── Memory Layer (Runtime)
│   └── $data[]
└── Persistence Layer (Files)
    ├── projects.json
    ├── boards.json
    ├── tasks.json
    ├── tags.json
    └── board_columns.json
```

### Стратегии кэширования

#### 1. Write-Through
Данные записываются одновременно в кэш и файл.

#### 2. Lazy Loading
Данные загружаются из файла только при первом обращении.

#### 3. Cache Invalidation
Кэш обновляется при изменении данных через API.

### Жизненный цикл кэша

```
1. Startup → CacheLoader.loadAllData()
2. Request → Cache.get() → loadFromFile() if not in memory
3. Modification → Cache.set() → saveToFile()
4. Shutdown → Cache.saveAll()
```

## Обработка ошибок

### Многоуровневая архитектура ошибок

```
┌─────────────────────────────────────────────────────────────┐
│ Level 1: MCP Protocol Errors                               │
│ - JSON-RPC validation                                       │
│ - Method not found                                          │
│ - Invalid parameters                                        │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│ Level 2: Business Logic Errors                             │
│ - Missing required parameters                               │
│ - Validation failures                                       │
│ - Business rule violations                                  │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│ Level 3: External API Errors                               │
│ - Weeek API errors                                          │
│ - Network timeouts                                          │
│ - Authentication failures                                   │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│ Level 4: System Errors                                     │
│ - File system errors                                        │
│ - Memory errors                                             │
│ - Configuration errors                                      │
└─────────────────────────────────────────────────────────────┘
```

### Error Handling Strategy

```php
try {
    // Business logic
    $result = $this->performOperation();
    
    // Update cache
    $this->cache->set($key, $result);
    
    return $this->successResponse($result);
    
} catch (WeeekApiException $e) {
    $this->log("API Error: " . $e->getMessage());
    return $this->errorResponse($e->getMessage(), 502);
    
} catch (ValidationException $e) {
    return $this->errorResponse($e->getMessage(), 400);
    
} catch (Exception $e) {
    $this->log("System Error: " . $e->getMessage());
    return $this->errorResponse("Internal error", 500);
}
```

## Производительность

### Оптимизации

#### 1. Кэширование
- Локальное кэширование для минимизации API вызовов
- Интеллектуальная инвалидация кэша
- Batch loading операций

#### 2. Memory Management
- Lazy loading данных
- Cleanup неиспользуемых данных
- Оптимизированная сериализация

#### 3. I/O Optimization
- Buffered reading/writing
- Асинхронные операции где возможно
- Connection pooling (планируется)

### Метрики производительности

```php
// Пример сбора метрик
class PerformanceCollector 
{
    public function measureApiCall($method, callable $operation) {
        $start = microtime(true);
        $result = $operation();
        $duration = microtime(true) - $start;
        
        $this->log("API Call: {$method}, Duration: {$duration}s");
        return $result;
    }
}
```

## Безопасность

### Архитектурные принципы безопасности

#### 1. Authentication
- API токены передаются через защищенные каналы
- Токены не логируются в открытом виде
- Валидация токенов на каждый запрос

#### 2. Authorization
- Проверка прав доступа к ресурсам
- Изоляция данных между пользователями
- Principle of least privilege

#### 3. Data Protection
- Шифрование чувствительных данных в кэше
- Безопасное хранение конфигурации
- Валидация всех входных данных

#### 4. Audit Trail
- Логирование всех операций
- Трассировка запросов
- Мониторинг подозрительной активности

### Security Layer Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ Input Validation Layer                                      │
│ - Parameter sanitization                                    │
│ - Type checking                                             │
│ - Range validation                                          │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│ Authentication Layer                                        │
│ - Token validation                                          │
│ - User identification                                       │
│ - Session management                                        │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│ Authorization Layer                                         │
│ - Resource access control                                   │
│ - Operation permissions                                     │
│ - Context-aware decisions                                   │
└─────────────────────────────────────────────────────────────┘
```

## Расширяемость

### Plugin Architecture (Планируется)

```php
interface ToolPlugin 
{
    public function getName(): string;
    public function getDescription(): string;
    public function getParameters(): array;
    public function execute(array $params): array;
}

class PluginManager 
{
    private array $plugins = [];
    
    public function registerPlugin(ToolPlugin $plugin) {
        $this->plugins[$plugin->getName()] = $plugin;
    }
    
    public function executePlugin(string $name, array $params) {
        return $this->plugins[$name]->execute($params);
    }
}
```

### Configuration Extensions

```php
// Конфигурация расширений
{
  "extensions": {
    "custom_notifications": {
      "enabled": true,
      "class": "Extensions\\NotificationPlugin"
    },
    "advanced_reporting": {
      "enabled": false,
      "class": "Extensions\\ReportingPlugin"
    }
  }
}
```

## Мониторинг и логирование

### Logging Architecture

```
┌─────────────────┐
│ Application     │
│ Logs            │
├─────────────────┤
│ • Business      │
│   operations    │
│ • User actions  │
│ • Performance   │
│   metrics       │
└─────────────────┘

┌─────────────────┐
│ System Logs     │
├─────────────────┤
│ • Error traces  │
│ • Debug info    │
│ • Resource      │
│   usage         │
└─────────────────┘

┌─────────────────┐
│ Audit Logs      │
├─────────────────┤
│ • Security      │
│   events        │
│ • Access        │
│   patterns      │
│ • Data changes  │
└─────────────────┘
```

### Monitoring Points

1. **Performance Metrics**
   - Response times
   - Memory usage
   - Cache hit rates
   - API call frequencies

2. **Error Rates**
   - Exception frequencies
   - Failed requests
   - Timeout incidents

3. **Business Metrics**
   - Tool usage statistics
   - User activity patterns
   - Feature adoption rates

## Развертывание

### Deployment Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ Production Environment                                      │
│                                                             │
│ ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│ │   Load      │  │   Weeek     │  │   Cache     │        │
│ │ Balancer    │  │ MCP Server  │  │   Storage   │        │
│ │             │  │   (N inst)  │  │   (Shared)  │        │
│ └─────────────┘  └─────────────┘  └─────────────┘        │
│                                                             │
│ ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│ │ Monitoring  │  │    Logs     │  │   Config    │        │
│ │   & Alerts  │  │ Aggregation │  │ Management  │        │
│ └─────────────┘  └─────────────┘  └─────────────┘        │
└─────────────────────────────────────────────────────────────┘
```

### Scaling Strategies

#### 1. Горизонтальное масштабирование
- Запуск нескольких экземпляров сервера
- Shared cache storage (Redis/Memcached)
- Load balancing между экземплярами

#### 2. Вертикальное масштабирование
- Увеличение ресурсов сервера
- Оптимизация использования памяти
- Профилирование и оптимизация кода

## Будущие улучшения

### Roadmap

#### Версия 2.0
- [ ] WebSocket поддержка для real-time обновлений
- [ ] Plugin система для расширений
- [ ] Distributed caching (Redis)
- [ ] Advanced metrics collection

#### Версия 2.1
- [ ] Multi-tenant архитектура
- [ ] Database storage option
- [ ] Advanced security features
- [ ] GraphQL API support

#### Версия 3.0
- [ ] Микросервисная архитектура
- [ ] Event-driven architecture
- [ ] Machine learning интеграция
- [ ] Advanced analytics

---

Эта архитектура обеспечивает баланс между простотой, производительностью и расширяемостью, позволяя системе эффективно обрабатывать запросы и масштабироваться по мере роста нагрузки. 