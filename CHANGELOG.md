# Changelog

> 本文件随版本发布进入仓库（自 3.1.0 起，此前仅本地维护）。

## [3.1.0] - 2026-08-06

### ✨ 新增能力

- **组合键检查**：`Context::hasAll(array $keys): bool`（全部存在才为 true，
  空数组返回 true）与 `Context::hasAny(array $keys): bool`（存在一个即为 true，
  空数组返回 false），便于批量校验上下文键。
- **事务作用域 `Context::transaction(callable): mixed`**：在「当前上下文」中执行回调，
  允许读写外部可见的上下文，但无论回调成功、返回还是抛异常，离开作用域都会恢复进入前的
  全部键值，避免临时写入污染调用方上下文；异常原样向上传播。

### ✓ 测试

- 新增 `tests/Unit/CompositeAndTransactionTest.php`（6 例）覆盖 `hasAll` / `hasAny` /
  `transaction` 的成功与异常回滚路径。
- 全量 `phpunit` → **OK (182 tests, 1539 assertions)**，2 例因缺 `pcntl`/`parallel` 扩展跳过。

## [3.0.0] - 2026-08-04

### ⚠️ 破坏性变更（Breaking Changes）

- **最低运行环境提升至 PHP 8.3+**（原 `^8.1` → `^8.3`）。
- **移除 pthreads 依赖**：多线程隔离仅保留 `parallel`（ZTS）扩展实现。
- **Fiber 隔离模型重构**：从「全局 `static` 初始化标志 + 引用绑定」改为
  `WeakMap<执行单元对象, ContextStore>` 的「每执行单元独占存储」。
  - 修复旧版在多个并发 Fiber 之间发生**上下文串号**的根本缺陷。
  - 执行单元 GC 后存储自动回收，无内存泄漏。

### ✨ 新增能力

- **W3C Trace Context 标准**：`TraceContext` 工具类 + `Context` 集成方法，
  支持 `traceparent` / `tracestate` / `baggage`，可与 OpenTelemetry 互通。
  - `Context::toTraceparent()` / `fromTraceparent()`
  - `Context::toW3CHeaders()` / `fromW3CHeaders()`
  - `Context::setBaggage()` / `getBaggage()`
  - `Context::continueTrace()` / `isSampled()` / `setSampled()`
- **安全反序列化 `ValueSerializer`**：跨进程 / 跨节点传递时默认不还原未知对象为实例，
  配合白名单 `allow()` 与自定义编解码 `register()`，杜绝对象注入。
- **类型安全访问器**：`getString` / `getInt` / `getFloat` / `getBool` / `getArray` /
  `getOrFail` / `getOfType`。
- **便捷 API**：`getOrSet` / `add` / `pull` / `increment` / `decrement` / `push` /
  `only` / `except`。
- **作用域 RAII `ContextScope`**：`Context::enter()` 返回句柄，`close()` 或析构时自动回滚。
- **运行时枚举 `Runtime`**：`Fiber / Swoole / Swow / Thread / Process / Sync`，
  暴露 `isCoroutine()` / `sharesMemory()` / `label()`。
- **作用域增强**：`runWith()` / `with()` / `bind()` / `depth()`；监听器支持通配 `*`（`WILDCARD`）。
- **多进程增强**：`runInProcess()` 返回 PID、`waitProcess()` 等待子进程；
  `parallelProcesses()` 改用 `stream_socket_pair` 并先读干管道再回收，修复死锁。

### 🐛 修复

- 修复 Fiber 上下文污染（见上方破坏性变更）。
- 修复并行进程管道通信死锁。
- 修复 `ContextException` 工厂方法缺失语义化构造。

### 🧪 测试

- 原有测试 85 项全绿；新增 `ScopeIsolationTest`(15) / `TraceContextTest`(32) /
  `ValueSerializerTest`(16) / `AccessorTest`(28) 共 91 项。
- 全量 `phpunit` **176 项全绿**（2 项因缺少 parallel/swoole/swow 扩展跳过）。
- `phpstan` 静态分析 **level max 零错误**。

### 📦 依赖

- `phpunit` `^10.5 || ^11.0`
- `phpstan` `^1.11 || ^2.0`
- 新增 `suggest`：ext-pcntl / parallel / swoole / swow
- branch-alias：`3.0.x-dev`
