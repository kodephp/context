# kode/context - PHP 协程/纤程上下文管理包

![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)

![License](https://img.shields.io/badge/License-Apache%202.0-green)

![Latest Version](https://img.shields.io/packagist/v/kode/context)

> **为多线程、多进程、协程（Swoole/Swow/Fiber）环境提供安全的请求上下文传递机制，支持分布式多机器部署**

---

## 📌 概述

在现代 PHP 高并发编程中，尤其是在使用 **协程（Coroutine）** 或 **纤程（Fiber）** 的场景下，传统的全局变量、静态属性或单例模式极易导致**上下文污染**和**数据错乱**。例如，在一个 HTTP 请求中存储用户信息、Trace ID、请求对象等，若直接使用 `static` 变量或全局容器，多个并发协程会共享同一份内存，造成严重安全隐患。

`kode/context` 是一个轻量级、高性能、跨运行时的上下文管理库，旨在解决：

- ✅ Fiber 中 `static` 变量被共享导致的数据污染
- ✅ Swoole/Swow 协程间上下文隔离问题
- ✅ 多进程（pcntl_fork）上下文继承与隔离
- ✅ 多线程（ZTS + parallel）上下文隔离
- ✅ 支持透明传递请求上下文（如：`user`, `request`, `trace_id`）
- ✅ 提供与 Go `context.Context` 类似的语义模型
- ✅ 兼容原生 PHP、Fiber（PHP 8.3+）、Swoole、Swow 等多种运行时环境
- ✅ 最低要求 PHP 8.3+（基于 WeakMap、enum、类型化常量等现代特性）
- ✅ 使用 final 类、类型安全、反射等更安全的方式实现
- ✅ **支持分布式多机器部署的上下文传递**

---

## 🎯 为什么需要 `kode/context`？

| 场景                | 问题                        | 解决方案        |
| ----------------- | ------------------------- | ----------- |
| 原生 PHP + 多进程      | 进程隔离，无需担心共享状态             | ✅ 安全        |
| 原生 PHP + 多线程（ZTS） | 线程共享内存，`static` 被所有线程共享   | ❌ 存在风险      |
| Swoole 协程         | 协程共享线程内存，`static` 被复用     | ❌ 极易污染      |
| Swow 协程           | 同上，绿色线程模型                 | ❌ 存在上下文混淆   |
| PHP 8.3+ Fiber    | Fiber 共享调用栈中的 `static` 变量 | ❌ 数据交叉污染    |
| **分布式多机器**        | 跨节点调用时上下文丢失               | ✅ **序列化传递** |

👉 **结论：只要存在"并发执行单元共享主线程内存"的情况，就必须使用上下文隔离机制！**

> 🔥 特别提醒：这是解决 Facade 模式、Service Locator、静态容器等"全局状态"污染的关键！

---

## 🧩 核心功能

```php
use Kode\Context\Context;

// 设置上下文值
Context::set('user', $user);

// 获取上下文值
$request = Context::get('request');

// 判断是否存在
if (Context::has('trace_id')) { ... }

// 删除键
Context::delete('tmp_data');

// 复制当前上下文快照
$ctx = Context::copy();

// 在新上下文中运行闭包（不影响父上下文）
Context::run(fn() => {
    Context::set('temp', 'value');
    // ...
}); // 自动恢复原始上下文

// 继承当前上下文运行闭包
Context::fork(fn() => {
    // 可以访问外部上下文
    $user = Context::get('user');
    Context::set('temp', 'value'); // 不影响外部
});

// 清空当前上下文
Context::clear();
```

---

## ✨ v3.0 重大升级

v3.0 是一次架构性重构，目标是**彻底解决并发执行单元间的上下文污染**，并补齐企业级可观测性与安全能力。

### 1. 真正的 Fiber / 协程隔离（WeakMap）

旧版使用全局 `static` 初始化标志 + 引用绑定，在多个 Fiber 并发时会发生**数据串号**（一个 Fiber 读到另一个 Fiber 写入的值）。v3.0 改为以「执行单元对象」（`Fiber` / Swoole 协程 / Swow 协程 / 线程）为键，存入 `WeakMap`，每个单元持有**完全独立的 `ContextStore`**：

- Fiber A 写入 `user=alice`，Fiber B 永远读不到，反之亦然；
- 执行单元结束、对象被 GC 后，`WeakMap` 自动回收存储，无内存泄漏；
- 同步环境退化为全局根存储，行为与旧版一致。

### 2. W3C Trace Context 标准（OpenTelemetry 互通）

新增 `TraceContext` 与 `Context` 集成方法，原生支持 `traceparent` / `tracestate` / `baggage`，可与 OpenTelemetry 无缝对接：

```php
use Kode\Context\Context;

// 生成标准 traceparent：00-<32位traceId>-<16位spanId>-01
$parent = Context::toTraceparent();          // "00-4bf9...-00f0...-01"

// 从上游继承（HTTP 网关 / 消息队列）
Context::fromTraceparent($header, 'rojo=1'); // 自动创建子 span

// 以 HTTP Header 形式跨服务传递
$headers = Context::toW3CHeaders();          // ['traceparent'=>..., 'tracestate'=>..., 'baggage'=>...]
Context::fromW3CHeaders($incoming);          // 还原上下文

// 业务 baggage（透传业务 KV，如 tenant_id）
Context::setBaggage('tenant_id', 'acme');
Context::getBaggage('tenant_id');            // "acme"
```

### 3. 安全反序列化（ValueSerializer）

跨进程 / 跨节点传递上下文时，默认**不还原未知对象为实例**，仅保留数组形态，杜绝对象注入攻击；支持白名单与自定义编解码：

```php
use Kode\Context\ValueSerializer;

// 仅允许可信类型还原为对象
ValueSerializer::allow(MyDto::class);

// 注册自定义编解码（如 DateTime / enum）
ValueSerializer::register(\DateTimeImmutable::class,
    fn ($dt) => $dt->format('c'),
    fn ($s)  => new \DateTimeImmutable($s)
);

$wire = ValueSerializer::encode($value);  // 安全编码
$back = ValueSerializer::decode($wire);   // 安全还原（恶意载荷降级为数组）
```

### 4. 类型安全访问器与便捷 API

```php
Context::getString('name');        // ?string
Context::getInt('count');          // ?int
Context::getBool('enabled');       // ?bool
Context::getArray('list');         // ?array
Context::getOrFail('token');       // 缺失即抛 ContextException

// 一次性 / 原子操作
Context::getOrSet('seq', fn() => 1);
Context::increment('hits');
Context::push('queue', $job);
$val = Context::pull('once');      // 取值并删除
```

### 5. 作用域 RAII（ContextScope）

`enter()` 返回一个作用域句柄，离开作用域（或显式 `close()`）时自动回滚，避免手动 `restore` 遗漏：

```php
$scope = Context::enter(['request_id' => 'r-1']);
// ... 在作用域内工作，修改互不影响外部
$scope->close(); // 或等 $scope 被 GC 时自动回滚
```

### 6. Runtime 枚举

`Kode\Context\Runtime` 以枚举形式描述当前运行时（Fiber / Swoole / Swow / Thread / Process / Sync），并暴露 `isCoroutine()` / `sharesMemory()` / `label()` 等语义查询。

---

## ⚙️ 实现原理（按运行时自动适配）

| 运行时环境                | 上下文存储机制                            | 说明                                 |
| -------------------- | ---------------------------------- | ---------------------------------- |
| **PHP Fiber (8.3+)** | `WeakMap<Fiber, ContextStore>`     | 以当前 Fiber 对象为键持有独立存储，Fiber 回收后自动释放 |
| **Swoole**           | `WeakMap<Coroutine, ContextStore>` | 基于协程对象绑定独立存储                       |
| **Swow**             | `WeakMap<Coroutine, ContextStore>` | 基于协程对象绑定独立存储                       |
| **多线程 (ZTS)**        | 线程 ID + 独立存储                       | 支持 parallel 扩展（ZTS 构建）             |
| **多进程**              | 进程 ID + fork 继承                    | 支持 pcntl_fork                      |
| **普通同步环境**           | 全局根存储                              | 单线程安全，兼容 CLI/HTTP                  |

> ✅ v3.0 起，所有并发运行时统一采用 **`WeakMap<执行单元对象, 上下文存储>`** 实现真正的「每执行单元独占隔离」，从根本上修复了旧版 Fiber 共享存储导致的数据污染问题（旧版 `static` 全局标志 + 引用绑定会在多个 Fiber 间串数据）。

> ✅ 所有实现均保证：**每个并发执行单元拥有独立的上下文视图**

---

## 🧪 快速开始

### 1. 安装

```bash
composer require kode/context
```

### 2. 基本用法

```php
use Kode\Context\Context;

// 设置一些上下文数据
Context::set('user_id', 123);
Context::set('trace_id', uniqid('trace_'));

// 在任意深度获取
function getCurrentUser() {
    return UserService::find(Context::get('user_id'));
}

// 输出 trace_id
echo Context::get('trace_id'); // e.g., trace_abc123
```

### 3. 使用 `Context::run()` 创建隔离作用域

```php
Context::set('role', 'admin');

Context::run(function () {
    Context::set('role', 'guest'); // 不影响外部
    echo Context::get('role'); // "guest"
});

echo Context::get('role'); // 仍然是 "admin"
```

### 4. 使用 `Context::fork()` 继承上下文

```php
Context::set('user_id', 123);

Context::fork(function () {
    // 可以访问外部上下文
    echo Context::get('user_id'); // 123
    
    // 修改不影响外部
    Context::set('user_id', 456);
});

echo Context::get('user_id'); // 仍然是 123
```

### 5. 结合中间件使用（如 Swoole HTTP Server）

```php
$http->on('request', function ($req, $resp) {
    Context::set('request', $req);
    Context::set('response', $resp);
    Context::set('trace_id', generateTraceId());

    try {
        $handler->handle(); // 在业务逻辑中可随时通过 Context::get() 获取
    } catch (\Throwable $e) {
        Log::error($e->getMessage(), ['trace_id' => Context::get('trace_id')]);
        $resp->end('Server Error');
    }
});
```

---

## 🔀 多进程支持

`kode/context` 提供完整的多进程上下文管理支持，适用于使用 `pcntl_fork()` 的场景。

### Fork 上下文继承

```php
use Kode\Context\Context;

// 设置父进程上下文
Context::set('user_id', 123);
Context::set('trace_id', 'abc-123');

// 准备 fork
Context::prepareFork();

$pid = pcntl_fork();

if ($pid === 0) {
    // 子进程：继承父进程上下文
    Context::afterFork(true);
    
    echo Context::get('user_id'); // 123
    echo Context::get('trace_id'); // 'abc-123'
    
    // 子进程的修改不影响父进程
    Context::set('user_id', 456);
    
    exit(0);
} else {
    // 父进程
    pcntl_wait($status);
    
    echo Context::get('user_id'); // 仍然是 123
}
```

### 进程池并行执行

```php
use Kode\Context\Context;

// 设置共享上下文
Context::set('config', $config);

// 定义任务
$tasks = [
    'task1' => fn() => processUserData($users1),
    'task2' => fn() => processUserData($users2),
    'task3' => fn() => generateReport($data),
];

// 并行执行（最大 4 个进程）
$results = Context::parallelProcesses($tasks, maxProcesses: 4, inheritContext: true);

// 获取结果
print_r($results['task1']);
print_r($results['task2']);
print_r($results['task3']);
```

### 进程间通信

进程间通过 socket 传递序列化数据：

```php
// parallelProcesses 自动处理进程间通信
$results = Context::parallelProcesses([
    'heavy_task' => fn() => [
        'status' => 'success',
        'data' => $computedData,
    ],
]);
```



---

## 🧵 多线程支持

`kode/context` 支持多线程环境（需要 ZTS + parallel 扩展）。

### 检测线程环境

```php
use Kode\Context\Context;

if (Context::isThread()) {
    echo "运行在多线程环境中";
}

$threadId = Context::getThreadId();
```

### 线程中运行任务

```php
use Kode\Context\Context;

// 设置共享上下文
Context::set('shared_data', $data);

// 在新线程中运行（继承上下文），返回 parallel\Future
$future = Context::runInThread(function () {
    // 可以访问共享上下文
    $data = Context::get('shared_data');
    return processInThread($data);
}, inheritContext: true);

// 阻塞获取结果（parallel 扩展）
$result = $future->value();
```

### 线程池并行执行

```php
use Kode\Context\Context;

$tasks = [
    'task1' => fn() => computeTask1(),
    'task2' => fn() => computeTask2(),
    'task3' => fn() => computeTask3(),
];

// 并行执行（最大 4 个线程）
$results = Context::parallelThreads($tasks, maxThreads: 4, inheritContext: true);
```

---

## 🌐 分布式支持

`kode/context` 提供完整的分布式上下文传递支持，适用于微服务、多机器部署场景。

### 分布式追踪

```php
use Kode\Context\Context;

// 在入口处启动追踪
$traceId = Context::startTrace(null, 'node-1');

// 获取追踪信息
$traceInfo = Context::getTraceInfo();
// ['trace_id' => '...', 'span_id' => '...', 'parent_span_id' => null, 'node_id' => 'node-1']

// 创建子 Span
$spanId = Context::startSpan();
```

### W3C Trace Context（推荐）

v3.0 原生支持 W3C `traceparent` / `tracestate` / `baggage` 规范，可与 OpenTelemetry 等标准链路系统直接互通：

```php
use Kode\Context\Context;

// 当前服务作为链路入口，生成标准 traceparent
Context::startTrace();

$parent = Context::toTraceparent();   // "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"

// 收到上游请求时，从 traceparent 继承并自动派生子 span
Context::fromTraceparent($incomingTraceparent, 'rojo=1');

// 以标准 HTTP Header 形式透传给下游
$headers = Context::toW3CHeaders();   // ['traceparent'=>..., 'tracestate'=>..., 'baggage'=>...]

// 在下游服务还原
Context::fromW3CHeaders($request->headers->all());

// 透传业务 baggage（如租户信息，不污染业务上下文）
Context::setBaggage('tenant_id', 'acme');
```

### 序列化与反序列化

```php
// 序列化为 JSON（用于跨节点传递）
$json = Context::toJson();
// 或仅序列化分布式追踪相关的键
$json = Context::toJson(Context::getDistributedKeys());

// 从 JSON 反序列化
Context::fromJson($json);        // 替换当前上下文
Context::fromJson($json, true);  // 合并到当前上下文
```

### HTTP Headers 传递

```php
// 导出为 HTTP Headers（用于 HTTP 客户端请求）
$headers = Context::toHeaders();
// ['X-Context-Trace-Id' => '...', 'X-Context-Span-Id' => '...', ...]

// 在服务端从 Headers 导入
Context::fromHeaders($request->headers->all());
```

### 完整分布式调用示例

```php
// === 节点 A（调用方） ===
Context::startTrace(null, 'node-a');
Context::set('user_id', 123);

// 准备跨节点调用
$headers = Context::toHeaders();
$response = $httpClient->post('http://node-b/api', [
    'headers' => $headers,
    'json' => ['data' => '...']
]);

// === 节点 B（被调用方） ===
// 从请求中恢复上下文
Context::fromHeaders($request->headers->all());

// 现在可以访问追踪信息
$traceId = Context::get(Context::TRACE_ID);
$sourceNode = Context::get(Context::NODE_ID); // 'node-a'

// 创建子 Span
Context::startSpan();

// 业务逻辑...
```

### 与 kode/fibers 集成

`kode/context` 可以与 `kode/fibers` 无缝集成，在分布式任务调度中自动传递上下文：

```php
use Kode\Context\Context;
use Kode\Fibers\Fibers;

// 设置分布式追踪上下文
Context::startTrace(null, 'node-1');

// 使用 Fibers 进行分布式任务调度
$result = Fibers::scheduleDistributedRemote(
    ['task1' => fn() => doWork()],
    ['node-2' => ['weight' => 1]],
    new HttpNodeTransport() // 自定义传输实现
);
```

---

## 🔄 API 文档

### 基础操作

| 方法                                                        | 说明      |
| --------------------------------------------------------- | ------- |
| `Context::set(string $key, mixed $value): void`           | 设置上下文值  |
| `Context::get(string $key, mixed $default = null): mixed` | 获取上下文值  |
| `Context::has(string $key): bool`                         | 判断键是否存在 |
| `Context::hasAll(array $keys): bool`                      | 判断所有指定键是否均存在（空数组返回 true） |
| `Context::hasAny(array $keys): bool`                      | 判断是否存在任意一个指定键（空数组返回 false） |
| `Context::delete(string $key): void`                      | 删除指定键   |
| `Context::clear(): void`                                  | 清空当前上下文 |

### 批量操作

| 方法                                                          | 说明           |
| ----------------------------------------------------------- | ------------ |
| `Context::copy(): array`                                    | 复制当前上下文为数组快照 |
| `Context::restore(array $snapshot): void`                   | 从快照恢复上下文     |
| `Context::merge(array $data, bool $overwrite = true): void` | 合并数据到上下文     |
| `Context::keys(): array`                                    | 获取所有键名       |
| `Context::count(): int`                                     | 获取键值对数量      |
| `Context::all(): array`                                     | 获取所有数据       |

### 作用域操作

| 方法                                                                    | 说明                     |
| --------------------------------------------------------------------- | ---------------------- |
| `Context::run(callable $callable): mixed`                             | 在隔离作用域中执行              |
| `Context::fork(callable $callable): mixed`                            | 在继承作用域中执行              |
| `Context::runWith(array $initial, callable $callable): mixed`         | 以指定初始数据运行隔离作用域         |
| `Context::with(array $values, callable $callable): mixed`             | 批量注入键值后运行隔离作用域         |
| `Context::enter(?array $initial = null): ContextScope`                | 进入作用域，返回 RAII 句柄（自动回滚） |
| `Context::bind(callable $callable, ?array $snapshot = null): Closure` | 绑定当前上下文到闭包，跨执行单元透传     |
| `Context::depth(): int`                                               | 当前作用域嵌套深度              |
| `Context::transaction(callable $callback): mixed`                     | 在当前上下文执行回调，无论成功/异常均自动回滚到进入前快照 |

### 类型安全

| 方法                                                                  | 说明                         |
| ------------------------------------------------------------------- | -------------------------- |
| `Context::getOfType(string $key, string $type): object`             | 获取并断言对象类型                  |
| `Context::getString(string $key, ?string $default = null): ?string` | 类型安全获取字符串                  |
| `Context::getInt(string $key, ?int $default = null): ?int`          | 类型安全获取整数                   |
| `Context::getFloat(string $key, ?float $default = null): ?float`    | 类型安全获取浮点                   |
| `Context::getBool(string $key, ?bool $default = null): ?bool`       | 类型安全获取布尔                   |
| `Context::getArray(string $key, ?array $default = null): ?array`    | 类型安全获取数组                   |
| `Context::getOrFail(string $key): mixed`                            | 获取，缺失即抛 `ContextException` |

### 便捷 API

| 方法                                                                  | 说明                |
| ------------------------------------------------------------------- | ----------------- |
| `Context::getOrSet(string $key, Closure $factory): mixed`           | 不存在则惰性生成并写入       |
| `Context::add(string $key, mixed $value): bool`                     | 原子追加到集合（不存在则创建数组） |
| `Context::pull(string $key, mixed $default = null): mixed`          | 取值并删除             |
| `Context::increment(string $key, int\|float $step = 1): int\|float` | 自增（支持浮点）          |
| `Context::decrement(string $key, int\|float $step = 1): int\|float` | 自减                |
| `Context::push(string $key, mixed ...$values): void`                | 批量压入数组            |
| `Context::only(array $keys): array`                                 | 仅保留指定键的快照         |
| `Context::except(array $keys): array`                               | 排除指定键的快照          |

### 监听器

| 方法                                                         | 说明                       |
| ---------------------------------------------------------- | ------------------------ |
| `Context::listen(string $key, Closure $listener): string`  | 注册变更监听器，返回监听 ID（`*` 为通配） |
| `Context::unlisten(string $key, ?string $id = null): void` | 移除监听器                    |
| `Context::listenedKeys(): array`                           | 当前已注册监听的键列表              |

### 运行时信息

| 方法                                             | 说明                      |
| ---------------------------------------------- | ----------------------- |
| `Context::runtime(): Runtime`                  | 获取运行时枚举对象               |
| `Context::getRuntime(): string`                | 获取运行时类型                 |
| `Context::isCoroutine(): bool`                 | 是否在协程环境                 |
| `Context::isThread(): bool`                    | 是否在线程环境                 |
| `Context::isProcess(): bool`                   | 是否在进程环境                 |
| `Context::isMain(): bool`                      | 是否主执行单元（非子 Fiber/协程/线程） |
| `Context::isPostFork(): bool`                  | 是否处于 fork 之后            |
| `Context::getExecutionId(): int\|string\|null` | 获取执行单元 ID               |
| `Context::getCoroutineId(): int\|string\|null` | 获取协程 ID                 |
| `Context::getProcessId(): int`                 | 获取进程 ID                 |
| `Context::getThreadId(): ?int`                 | 获取线程 ID                 |

### 多进程操作

| 方法                                                                                    | 说明               |
| ------------------------------------------------------------------------------------- | ---------------- |
| `Context::prepareFork(): void`                                                        | 准备 fork 前的上下文快照  |
| `Context::afterFork(bool $inherit = true): void`                                      | fork 后初始化子进程上下文  |
| `Context::runInProcess(callable $task, bool $inherit = true): int`                    | 在子进程中运行任务，返回 PID |
| `Context::waitProcess(int $pid): int`                                                 | 等待指定子进程结束        |
| `Context::parallelProcesses(array $tasks, int $max = 4, bool $inherit = true): array` | 进程池并行执行          |

### 多线程操作

| 方法                                                                                  | 说明                            |
| ----------------------------------------------------------------------------------- | ----------------------------- |
| `Context::runInThread(callable $task, bool $inherit = true): object`                | 在线程中运行任务，返回 `parallel\Future` |
| `Context::parallelThreads(array $tasks, int $max = 4, bool $inherit = true): array` | 线程池并行执行                       |

### 分布式操作

| 方法                                                                                | 说明                                                    |
| --------------------------------------------------------------------------------- | ----------------------------------------------------- |
| `Context::toJson(array $onlyKeys = []): string`                                   | 序列化为 JSON                                             |
| `Context::fromJson(string $json, bool $merge = false): array`                     | 从 JSON 反序列化                                           |
| `Context::export(array $onlyKeys = []): array`                                    | 导出可序列化数据                                              |
| `Context::import(array $data, bool $merge = false): array`                        | 导入数据                                                  |
| `Context::startTrace(?string $traceId = null, ?string $nodeId = null): string`    | 启动内部追踪                                                |
| `Context::startSpan(): string`                                                    | 创建子 Span                                              |
| `Context::getTraceInfo(): array`                                                  | 获取追踪信息                                                |
| `Context::isSampled(): bool`                                                      | 是否采样                                                  |
| `Context::setSampled(bool $sampled = true): void`                                 | 设置采样标志                                                |
| `Context::toTraceparent(): ?string`                                               | 导出 W3C `traceparent`                                  |
| `Context::fromTraceparent(string $traceparent, ?string $tracestate = null): bool` | 从 `traceparent` 继承（创建子 span）                          |
| `Context::setBaggage(string $key, string $value): void`                           | 设置 W3C `baggage` 键值                                   |
| `Context::getBaggage(?string $key = null): array\|string\|null`                   | 读取 `baggage`                                          |
| `Context::toW3CHeaders(): array`                                                  | 导出 `traceparent`/`tracestate`/`baggage` 为 HTTP Header |
| `Context::fromW3CHeaders(array $headers): bool`                                   | 从 HTTP Header 还原 W3C 上下文                              |
| `Context::toHeaders(string $prefix = 'X-Context-'): array`                        | 导出为自定义 Headers                                        |
| `Context::fromHeaders(array $headers, string $prefix = 'X-Context-'): void`       | 从自定义 Headers 导入                                       |
| `Context::continueTrace(array $headers, string $prefix = 'X-Context-'): void`     | 从上游 Header 继续追踪链路                                     |
| `Context::getDistributedKeys(): array`                                            | 获取分布式键                                                |
| `Context::exportForDistributed(): array`                                          | 导出分布式上下文                                              |

### 安全序列化（ValueSerializer）

跨进程 / 跨节点传递时用于安全编解码，默认不还原未知对象为实例（防注入）。

| 方法                                                                                 | 说明              |
| ---------------------------------------------------------------------------------- | --------------- |
| `ValueSerializer::allow(string ...$classes): void`                                 | 将类型加入可信还原白名单    |
| `ValueSerializer::register(string $class, Closure $encode, Closure $decode): void` | 注册自定义编解码器       |
| `ValueSerializer::isAllowed(string $class): bool`                                  | 是否在白名单          |
| `ValueSerializer::encode(mixed $value): mixed`                                     | 安全编码            |
| `ValueSerializer::decode(mixed $value): mixed`                                     | 安全还原（恶意载荷降级为数组） |

### 测试辅助

| 方法                       | 说明      |
| ------------------------ | ------- |
| `Context::reset(): void` | 重置上下文状态 |

### 常量

```php
// 运行时类型（v3.0 起全部为类型化常量 public const string）
Context::RUNTIME_FIBER    // 'fiber'
Context::RUNTIME_SWOOLE   // 'swoole'
Context::RUNTIME_SWOW     // 'swow'
Context::RUNTIME_THREAD   // 'thread'
Context::RUNTIME_PROCESS  // 'process'
Context::RUNTIME_SYNC     // 'sync'

// 分布式追踪键（W3C Trace Context）
Context::TRACE_ID         // 'trace_id'
Context::SPAN_ID          // 'span_id'
Context::PARENT_SPAN_ID   // 'parent_span_id'
Context::TRACE_FLAGS      // 'trace_flags'
Context::TRACE_STATE      // 'trace_state'
Context::BAGGAGE          // 'baggage'
Context::NODE_ID          // 'node_id'
Context::SOURCE_NODE_ID   // 'source_node_id'
Context::REQUEST_ID       // 'request_id'
Context::CORRELATION_ID   // 'correlation_id'

// 进程/线程键
Context::PROCESS_ID          // 'process_id'
Context::THREAD_ID           // 'thread_id'
Context::PARENT_PROCESS_ID   // 'parent_process_id'

// 监听器通配符
Context::WILDCARD         // '*'  监听所有键的变更
```

---

## 🧱 设计思想参考

- **Go 的 `context.Context`**  
  提供了 `WithValue`, `WithCancel`, `WithTimeout` 等组合能力，本包聚焦于最核心的 `value` 传递。
- **Swoole Coroutine\Context**  
  借鉴其基于协程 ID 的上下文映射机制，确保隔离性。
- **Hyperf\Context**  
  对标其静态代理接口设计，提供更简洁的 API。
- **OpenTelemetry**  
  分布式追踪设计参考了 OpenTelemetry 的 Trace/Span 模型。

---

## ✅ 适用场景

- 微服务架构中的链路追踪（Trace ID 透传）
- 用户身份认证上下文（User / Token）
- 日志上下文注入（Structured Logging）
- ORM 连接上下文（如 Tenant ID）
- AOP 拦截器中共享临时数据
- 替代 Facade 模式中的全局状态
- 多进程任务并行处理
- 多线程计算密集型任务
- 分布式任务调度与上下文传递

---

## 🚫 注意事项

- 不建议存放大量数据（影响性能）
- 不支持跨协程/纤程通信（仅传递快照）
- 不应在上下文中保存资源句柄（如文件描述符、数据库连接）
- Fiber 下注意闭包绑定问题（`$this` 上下文可能不同）
- 分布式传递时，对象会被序列化（经 `ValueSerializer` 安全处理），资源句柄和闭包无法传递
- 多进程功能需要 pcntl 扩展
- 多线程功能需要 ZTS + parallel 扩展（v3.0 起已移除 pthreads 依赖）
- 最低运行环境为 PHP 8.3+

---

## 📦 与其他组件集成建议

| 组件             | 集成方式                                     |
| -------------- | ---------------------------------------- |
| Hyperf         | 替代 `Hyperf\Context\Context`，作为底层依赖       |
| Laravel Octane | 在 onRequest 回调中初始化 Context               |
| EasySwoole     | 在主服务启动时注册 Context 初始化                    |
| Monolog        | 添加 `ProcessContextProcessor` 注入 trace_id |
| kode/fibers    | 作为底层依赖，支持分布式任务调度                         |

---

## 🧪 性能基准测试

`kode/context` 在多种环境下进行了性能测试，迭代次数 100,000 次。

### macOS (Apple Silicon)

| 方法                    | 执行时间    | 每秒操作数      |
| --------------------- | ------- | ---------- |
| `Context::set()`      | 8.53ms  | 11,723,570 |
| `Context::get()`      | 6.87ms  | 14,556,030 |
| `Context::has()`      | 6.53ms  | 15,322,044 |
| `Context::delete()`   | 12.44ms | 8,038,464  |
| `Context::clear()`    | 18.80ms | 5,320,011  |
| `Context::copy()`     | 6.64ms  | 15,064,102 |
| `Context::run()`      | 36.10ms | 2,770,016  |
| `Context::fork()`     | 42.50ms | 2,352,941  |
| `Context::toJson()`   | ~25ms   | ~4,000,000 |
| `Context::fromJson()` | ~30ms   | ~3,300,000 |

**测试环境：** macOS 14.4 (Darwin 24.3.0), Apple M3 Pro (11核), 18GB RAM, PHP 8.3.30, OPcache 启用

### Linux (x86_64)

| 方法                | 执行时间  | 每秒操作数       |
| ----------------- | ----- | ----------- |
| `Context::set()`  | ~7ms  | ~14,000,000 |
| `Context::get()`  | ~5ms  | ~20,000,000 |
| `Context::has()`  | ~5ms  | ~20,000,000 |
| `Context::run()`  | ~30ms | ~3,300,000  |
| `Context::fork()` | ~35ms | ~2,800,000  |

**测试环境：** Ubuntu 22.04 LTS, AMD EPYC/Ryzen, PHP 8.2+, OPcache 启用

### Windows (x86_64)

| 方法               | 执行时间 | 每秒操作数       |
| ---------------- | ---- | ----------- |
| `Context::set()` | ~9ms | ~11,000,000 |
| `Context::get()` | ~8ms | ~12,500,000 |
| `Context::has()` | ~8ms | ~12,500,000 |

**测试环境：** Windows 11, AMD Ryzen 7 5800H, 32GB RAM, PHP 8.2+

这些结果表明 `kode/context` 在各种操作上都具有出色的性能表现，适合在高并发环境中使用。

> 💡 **提示：** 实际性能会因硬件配置、PHP 版本、OPcache/JIT 状态等因素而有所不同。建议在正式环境中使用 OPcache 和 JIT 以获得最佳性能。

### 运行基准测试

```bash
composer run benchmark
```

---

## 🤝 贡献与反馈

欢迎提交 Issue 或 Pull Request！

GitHub: <https://github.com/kodephp/context>

---

## 📜 许可证

Apache License 2.0

---

> 🌟 `kode/context` —— 让每一次协程调用都清晰可控，告别上下文污染！
