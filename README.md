# kode/context - PHP 协程/纤程上下文管理包

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache%202.0-green)](LICENSE)
[![Latest Version](https://img.shields.io/packagist/v/kode/context)](https://packagist.org/packages/kode/context)

> **为多线程、多进程、协程（Swoole/Swow/Fiber）环境提供安全的请求上下文传递机制，支持分布式多机器部署**

---

## 📌 概述

在现代 PHP 高并发编程中，尤其是在使用 **协程（Coroutine）** 或 **纤程（Fiber）** 的场景下，传统的全局变量、静态属性或单例模式极易导致**上下文污染**和**数据错乱**。例如，在一个 HTTP 请求中存储用户信息、Trace ID、请求对象等，若直接使用 `static` 变量或全局容器，多个并发协程会共享同一份内存，造成严重安全隐患。

`kode/context` 是一个轻量级、高性能、跨运行时的上下文管理库，旨在解决：

- ✅ Fiber 中 `static` 变量被共享导致的数据污染
- ✅ Swoole/Swow 协程间上下文隔离问题
- ✅ 支持透明传递请求上下文（如：`user`, `request`, `trace_id`）
- ✅ 提供与 Go `context.Context` 类似的语义模型
- ✅ 兼容原生 PHP、Fiber（PHP 8.3+）、Swoole、Swow 等多种运行时环境
- ✅ 支持 PHP 8.1+ 并兼容 PHP 8.5 新特性
- ✅ 使用 final 类、类型安全、反射等更安全的方式实现
- ✅ **支持分布式多机器部署的上下文传递**

---

## 🎯 为什么需要 `kode/context`？

| 场景 | 问题 | 解决方案 |
|------|------|----------|
| 原生 PHP + 多进程 | 进程隔离，无需担心共享状态 | ✅ 安全 |
| 原生 PHP + 多线程（ZTS） | 线程共享内存，`static` 被所有线程共享 | ❌ 存在风险 |
| Swoole 协程 | 协程共享线程内存，`static` 被复用 | ❌ 极易污染 |
| Swow 协程 | 同上，绿色线程模型 | ❌ 存在上下文混淆 |
| PHP 8.3+ Fiber | Fiber 共享调用栈中的 `static` 变量 | ❌ 数据交叉污染 |
| **分布式多机器** | 跨节点调用时上下文丢失 | ✅ **序列化传递** |

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

## ⚙️ 实现原理（按运行时自动适配）

| 运行时环境 | 上下文存储机制 | 说明 |
|-----------|----------------|------|
| **PHP Fiber (8.3+)** | `\Fiber::getLocal()` | 使用 Fiber 内建本地存储，完美隔离 |
| **Swoole** | `Co::getCid()` + `Coroutine::getContext()` | 基于协程 ID 绑定上下文对象 |
| **Swow** | `Swow\Coroutine::getLocal()` | 使用 Swow 提供的本地存储 API |
| **普通同步环境** | `$GLOBALS` 模拟 | 单线程安全，兼容 CLI/HTTP |

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

#### `Context::set(string $key, mixed $value): void`
设置当前上下文中的值。

#### `Context::get(string $key, mixed $default = null): mixed`
获取指定键的值，不存在则返回默认值。

#### `Context::has(string $key): bool`
判断键是否存在。

#### `Context::delete(string $key): void`
删除指定键。

#### `Context::clear(): void`
清空当前上下文所有数据。

### 批量操作

#### `Context::copy(): array`
复制当前上下文为数组快照（用于调试或传递）。

#### `Context::restore(array $snapshot): void`
从快照恢复上下文。

#### `Context::merge(array $data, bool $overwrite = true): void`
将数组合并到当前上下文中。

#### `Context::keys(): array`
获取当前上下文中的所有键名。

#### `Context::count(): int`
获取当前上下文中的键值对数量。

#### `Context::all(): array`
获取当前上下文中的所有数据。

### 作用域操作

#### `Context::run(callable $callable): mixed`
在新的上下文作用域中执行 `$callable`，结束后自动回滚到之前的状态。

> 💡 类似于事务式的上下文操作，避免副作用泄漏。新作用域中无法访问外部上下文。

#### `Context::fork(callable $callable): mixed`
在继承当前上下文的新作用域中执行 `$callable`，结束后自动回滚。

> 💡 与 `run()` 不同，`fork()` 会复制当前上下文到新作用域，可以访问外部上下文。

### 类型安全

#### `Context::getOfType(string $key, string $type): mixed`
获取指定键的值并断言类型。

```php
$user = Context::getOfType('user', User::class);
// 如果值不存在或类型不匹配，抛出 ContextException
```

### 监听器

#### `Context::listen(string $key, Closure $listener): void`
注册上下文变更监听器。

```php
Context::listen('user_id', function (string $key, mixed $oldValue, mixed $newValue) {
    Log::info("用户ID变更: {$oldValue} -> {$newValue}");
});
```

#### `Context::unlisten(string $key): void`
移除上下文变更监听器。

### 运行时信息

#### `Context::getRuntime(): string`
获取当前运行时类型，返回以下常量之一：
- `Context::RUNTIME_FIBER` - PHP Fiber 环境
- `Context::RUNTIME_SWOOLE` - Swoole 协程环境
- `Context::RUNTIME_SWOW` - Swow 协程环境
- `Context::RUNTIME_SYNC` - 同步模式

#### `Context::isCoroutine(): bool`
检查是否在协程/Fiber环境中运行。

#### `Context::getCoroutineId(): int|string|null`
获取当前协程/Fiber ID，同步模式下返回 null。

### 分布式操作

#### `Context::toJson(array $onlyKeys = []): string`
序列化上下文为 JSON 字符串。

#### `Context::fromJson(string $json, bool $merge = false): array`
从 JSON 字符串反序列化上下文。

#### `Context::export(array $onlyKeys = []): array`
导出可序列化的上下文数据。

#### `Context::import(array $data, bool $merge = false): array`
导入上下文数据。

#### `Context::startTrace(?string $traceId = null, ?string $nodeId = null): string`
创建分布式追踪上下文。

#### `Context::startSpan(): string`
创建子 Span。

#### `Context::getTraceInfo(): array`
获取追踪信息。

#### `Context::toHeaders(string $prefix = 'X-Context-'): array`
导出为 HTTP Headers 格式。

#### `Context::fromHeaders(array $headers, string $prefix = 'X-Context-'): void`
从 HTTP Headers 导入上下文。

#### `Context::getDistributedKeys(): array`
获取分布式传递所需的上下文键。

#### `Context::exportForDistributed(): array`
导出分布式追踪上下文。

### 测试辅助

#### `Context::reset(): void`
重置上下文状态（主要用于测试）。

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
- **分布式任务调度与上下文传递**

---

## 🚫 注意事项

- 不建议存放大量数据（影响性能）
- 不支持跨协程/纤程通信（仅传递快照）
- 不应在上下文中保存资源句柄（如文件描述符、数据库连接）
- Fiber 下注意闭包绑定问题（`$this` 上下文可能不同）
- 分布式传递时，对象会被序列化，资源句柄和闭包无法传递

---

## 📦 与其他组件集成建议

| 组件 | 集成方式 |
|------|----------|
| Hyperf | 替代 `Hyperf\Context\Context`，作为底层依赖 |
| Laravel Octane | 在 onRequest 回调中初始化 Context |
| EasySwoole | 在主服务启动时注册 Context 初始化 |
| Monolog | 添加 `ProcessContextProcessor` 注入 trace_id |
| kode/fibers | 作为底层依赖，支持分布式任务调度 |

---

## 🧪 性能基准测试

`kode/context` 在多种环境下进行了性能测试，迭代次数 100,000 次。

### macOS (Apple Silicon)

| 方法 | 执行时间 | 每秒操作数 |
|------|---------|----------|
| `Context::set()` | 8.53ms | 11,723,570 |
| `Context::get()` | 6.87ms | 14,556,030 |
| `Context::has()` | 6.53ms | 15,322,044 |
| `Context::delete()` | 12.44ms | 8,038,464 |
| `Context::clear()` | 18.80ms | 5,320,011 |
| `Context::copy()` | 6.64ms | 15,064,102 |
| `Context::run()` | 36.10ms | 2,770,016 |
| `Context::fork()` | 42.50ms | 2,352,941 |
| `Context::toJson()` | ~25ms | ~4,000,000 |
| `Context::fromJson()` | ~30ms | ~3,300,000 |

**测试环境：** macOS 14.4 (Darwin 24.3.0), Apple M3 Pro (11核), 18GB RAM, PHP 8.3.30, OPcache 启用

### Linux (x86_64)

| 方法 | 执行时间 | 每秒操作数 |
|------|---------|----------|
| `Context::set()` | ~7ms | ~14,000,000 |
| `Context::get()` | ~5ms | ~20,000,000 |
| `Context::has()` | ~5ms | ~20,000,000 |
| `Context::run()` | ~30ms | ~3,300,000 |
| `Context::fork()` | ~35ms | ~2,800,000 |

**测试环境：** Ubuntu 22.04 LTS, AMD EPYC/Ryzen, PHP 8.2+, OPcache 启用

### Windows (x86_64)

| 方法 | 执行时间 | 每秒操作数 |
|------|---------|----------|
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

## 🆕 版本更新

### v2.1.0

**新功能：**
- 新增分布式上下文传递支持
- 新增 `Context::toJson()` / `Context::fromJson()` 序列化方法
- 新增 `Context::export()` / `Context::import()` 导入导出方法
- 新增 `Context::startTrace()` / `Context::startSpan()` 分布式追踪
- 新增 `Context::toHeaders()` / `Context::fromHeaders()` HTTP Headers 传递
- 新增 `Context::getDistributedKeys()` / `Context::exportForDistributed()` 分布式键管理
- 实现 `JsonSerializable` 接口

**改进：**
- 支持 DateTime、Enum、JsonSerializable 对象的序列化
- 完善分布式追踪信息管理

### v2.0.0

**新功能：**
- 新增 `Context::fork()` 方法，支持继承当前上下文
- 新增 `Context::restore()` 方法，支持从快照恢复上下文
- 新增 `Context::getOfType()` 方法，支持类型安全获取
- 新增 `Context::listen()` / `Context::unlisten()` 监听器机制
- 新增 `Context::getRuntime()` / `Context::isCoroutine()` / `Context::getCoroutineId()` 运行时检测方法
- 新增 `Context::reset()` 测试辅助方法
- 新增 `ContextException` 异常类

**改进：**
- 使用 `final` 类防止继承
- 使用 PHP 8.1+ 新特性（如 `mixed` 类型、命名参数等）
- 兼容 PHP 8.5 新特性
- 优化 Fiber 存储机制
- 完善测试覆盖率

---

## 🤝 贡献与反馈

欢迎提交 Issue 或 Pull Request！

GitHub: [https://github.com/kodephp/context](https://github.com/kodephp/context)

---

## 📜 许可证

Apache License 2.0

---

> 🌟 `kode/context` —— 让每一次协程调用都清晰可控，告别上下文污染！
