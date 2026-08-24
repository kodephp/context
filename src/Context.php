<?php

declare(strict_types=1);

namespace Kode\Context;

use Closure;
use Fiber;
use Throwable;
use WeakMap;

/**
 * 上下文管理器
 *
 * 为多进程、多线程、协程（Swoole/Swow/Fiber）环境提供安全的请求上下文传递机制，
 * 并支持分布式多机器部署下的上下文透传与链路追踪。
 *
 * 核心机制（v3.0 重构）：
 * 每次读写都会实时解析"当前执行单元"，并从 WeakMap 中取出该单元**独占**的存储对象。
 * 执行单元销毁后，WeakMap 会自动回收对应上下文，既保证隔离又不会泄漏内存。
 *
 * 支持的运行环境：
 * - PHP 原生 Fiber（纤程）
 * - Swoole / Swow 协程
 * - 多线程（ZTS + parallel）
 * - 多进程（pcntl_fork、进程池）
 * - 普通同步模式（CLI / FPM）
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
final class Context
{
    // ==================== 常量 ====================

    /** 分布式追踪：链路 ID */
    public const string TRACE_ID = 'trace_id';

    /** 分布式追踪：当前跨度 ID */
    public const string SPAN_ID = 'span_id';

    /** 分布式追踪：父跨度 ID */
    public const string PARENT_SPAN_ID = 'parent_span_id';

    /** 分布式追踪：追踪标志位（W3C trace-flags） */
    public const string TRACE_FLAGS = 'trace_flags';

    /** 分布式追踪：厂商透传状态（W3C tracestate） */
    public const string TRACE_STATE = 'trace_state';

    /** 分布式追踪：随行数据（W3C baggage） */
    public const string BAGGAGE = 'baggage';

    /** 当前节点 ID */
    public const string NODE_ID = 'node_id';

    /** 来源节点 ID */
    public const string SOURCE_NODE_ID = 'source_node_id';

    /** 请求 ID */
    public const string REQUEST_ID = 'request_id';

    /** 关联 ID */
    public const string CORRELATION_ID = 'correlation_id';

    /** 进程 ID */
    public const string PROCESS_ID = 'process_id';

    /** 线程 ID */
    public const string THREAD_ID = 'thread_id';

    /** 父进程 ID */
    public const string PARENT_PROCESS_ID = 'parent_process_id';

    /** 监听器通配符键名 */
    public const string WILDCARD = '*';

    /** 运行时：PHP Fiber */
    public const string RUNTIME_FIBER = 'fiber';

    /** 运行时：Swoole 协程 */
    public const string RUNTIME_SWOOLE = 'swoole';

    /** 运行时：Swow 协程 */
    public const string RUNTIME_SWOW = 'swow';

    /** 运行时：多线程 */
    public const string RUNTIME_THREAD = 'thread';

    /** 运行时：多进程 */
    public const string RUNTIME_PROCESS = 'process';

    /** 运行时：同步模式 */
    public const string RUNTIME_SYNC = 'sync';

    // ==================== 内部状态 ====================

    /**
     * 根存储：同步模式、多进程模式以及每个独立线程各自持有一份
     */
    private static ?ContextStore $rootStore = null;

    /**
     * 执行单元 => 存储 的弱引用映射，执行单元销毁后自动回收
     *
     * @var WeakMap<object, ContextStore>|null
     */
    private static ?WeakMap $scopedStores = null;

    /**
     * 上下文变更监听器
     *
     * @var array<string, array<string, Closure>>
     */
    private static array $listeners = [];

    /**
     * 监听器自增序号
     */
    private static int $listenerSeq = 0;

    /**
     * 进程级上下文快照（用于 fork 后继承）
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $processContexts = [];

    /**
     * 扩展探测缓存
     */
    private static ?bool $hasSwoole = null;

    private static ?bool $hasSwow = null;

    private static ?bool $hasParallel = null;

    /**
     * 当前线程的稳定标识（statics 在 parallel 中天然按线程隔离）
     */
    private static ?int $threadId = null;

    /**
     * 当前是否运行在 parallel 工作线程内（仅在线程内为 true）
     *
     * 用于区分「ZTS 构建 + parallel 扩展已加载」与「真正处于 parallel 线程中」，
     * 避免主线程（即使装了 parallel）被误判为多线程环境。
     */
    private static bool $inParallelThread = false;

    /**
     * 是否处于 fork 之后的子进程
     */
    private static bool $postFork = false;

    /**
     * parallel 工作线程引导文件（vendor/autoload.php）缓存
     *
     * parallel 工作线程不继承主线程的自动加载器，必须在创建 Runtime 时显式引导。
     *
     * @var string|null
     */
    private static ?string $parallelBootstrap = null;

    private function __construct()
    {
    }

    // ==================== 基础读写 ====================

    /**
     * 设置上下文值
     *
     * @param string $key   键名
     * @param mixed  $value 值
     */
    public static function set(string $key, mixed $value): void
    {
        $store = self::store();
        $oldValue = $store->data[$key] ?? null;
        $store->data[$key] = $value;
        self::triggerListener($key, $oldValue, $value);
    }

    /**
     * 获取上下文值
     *
     * @param string $key     键名
     * @param mixed  $default 默认值
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::store()->data;

        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * 获取上下文值，不存在时抛出异常
     *
     * @throws ContextException 键不存在时
     */
    public static function getOrFail(string $key): mixed
    {
        $data = self::store()->data;

        if (!array_key_exists($key, $data)) {
            throw ContextException::keyNotFound($key);
        }

        return $data[$key];
    }

    /**
     * 判断键是否存在
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::store()->data);
    }

    /**
     * 判断所有指定键是否均存在
     *
     * 空数组视为「全部存在」，返回 true。
     *
     * @param list<string> $keys 待检查的键名
     */
    public static function hasAll(array $keys): bool
    {
        $data = self::store()->data;
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 判断是否至少存在一个指定键
     *
     * 空数组视为「不存在任意一个」，返回 false。
     *
     * @param list<string> $keys 待检查的键名
     */
    public static function hasAny(array $keys): bool
    {
        $data = self::store()->data;
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 删除指定键
     */
    public static function delete(string $key): void
    {
        $store = self::store();

        if (!array_key_exists($key, $store->data)) {
            return;
        }

        $oldValue = $store->data[$key];
        unset($store->data[$key]);
        self::triggerListener($key, $oldValue, null);
    }

    /**
     * 清空当前上下文所有数据
     */
    public static function clear(): void
    {
        $store = self::store();
        $oldData = $store->data;
        $store->data = [];

        foreach ($oldData as $key => $value) {
            self::triggerListener($key, $value, null);
        }
    }

    /**
     * 复制当前上下文为数组快照
     *
     * @return array<string, mixed>
     */
    public static function copy(): array
    {
        return self::store()->data;
    }

    /**
     * 获取当前上下文中的所有数据（copy 的别名）
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::store()->data;
    }

    /**
     * 获取当前上下文中的所有键名
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::store()->data);
    }

    /**
     * 获取当前上下文中的键值对数量
     */
    public static function count(): int
    {
        return count(self::store()->data);
    }

    /**
     * 当前上下文是否为空
     */
    public static function isEmpty(): bool
    {
        return self::store()->data === [];
    }

    /**
     * 将数组合并到当前上下文中
     *
     * @param array<string, mixed> $data      要合并的数据
     * @param bool                 $overwrite 是否覆盖已存在的键
     */
    public static function merge(array $data, bool $overwrite = true): void
    {
        $store = self::store();

        foreach ($data as $key => $value) {
            if (!$overwrite && array_key_exists($key, $store->data)) {
                continue;
            }

            $oldValue = $store->data[$key] ?? null;
            $store->data[$key] = $value;
            self::triggerListener($key, $oldValue, $value);
        }
    }

    /**
     * 用快照整体替换当前上下文
     *
     * @param array<string, mixed> $snapshot 上下文快照
     */
    public static function restore(array $snapshot): void
    {
        $store = self::store();
        $oldData = $store->data;
        $store->data = $snapshot;

        foreach ($oldData as $key => $value) {
            if (!array_key_exists($key, $snapshot)) {
                self::triggerListener($key, $value, null);
            }
        }

        foreach ($snapshot as $key => $value) {
            if (!array_key_exists($key, $oldData) || $oldData[$key] !== $value) {
                self::triggerListener($key, $oldData[$key] ?? null, $value);
            }
        }
    }

    // ==================== 类型安全访问器 ====================

    /**
     * 获取上下文值并断言为指定类的实例
     *
     * @template T of object
     * @param string          $key  键名
     * @param class-string<T> $type 期望的类型
     * @return T
     * @throws ContextException 键不存在或类型不匹配
     */
    public static function getOfType(string $key, string $type): object
    {
        $value = self::store()->data[$key] ?? null;

        if ($value === null) {
            throw ContextException::keyNotFound($key);
        }

        if (!$value instanceof $type) {
            throw ContextException::typeMismatch($key, $type, $value);
        }

        return $value;
    }

    /**
     * 获取字符串值
     *
     * @throws ContextException 值存在但类型不符
     */
    public static function getString(string $key, ?string $default = null): ?string
    {
        $value = self::store()->data[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            throw ContextException::typeMismatch($key, 'string', $value);
        }

        return $value;
    }

    /**
     * 获取整数值（数字字符串会被安全转换）
     *
     * @throws ContextException 值存在但无法转换
     */
    public static function getInt(string $key, ?int $default = null): ?int
    {
        $value = self::store()->data[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }

        if (is_float($value) && (float)(int)$value === $value) {
            return (int)$value;
        }

        throw ContextException::typeMismatch($key, 'int', $value);
    }

    /**
     * 获取浮点值
     *
     * @throws ContextException 值存在但无法转换
     */
    public static function getFloat(string $key, ?float $default = null): ?float
    {
        $value = self::store()->data[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float)$value;
        }

        throw ContextException::typeMismatch($key, 'float', $value);
    }

    /**
     * 获取布尔值（兼容 "1"/"true"/"yes"/"on" 等常见表示）
     *
     * @throws ContextException 值存在但无法转换
     */
    public static function getBool(string $key, ?bool $default = null): ?bool
    {
        $value = self::store()->data[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw ContextException::typeMismatch($key, 'bool', $value);
    }

    /**
     * 获取数组值
     *
     * @param array<mixed>|null $default
     * @return array<mixed>|null
     * @throws ContextException 值存在但类型不符
     */
    public static function getArray(string $key, ?array $default = null): ?array
    {
        $value = self::store()->data[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_array($value)) {
            throw ContextException::typeMismatch($key, 'array', $value);
        }

        return $value;
    }

    // ==================== 便捷操作 ====================

    /**
     * 获取值，不存在时用工厂函数生成并写入
     *
     * @param string  $key     键名
     * @param Closure $factory 值工厂
     */
    public static function getOrSet(string $key, Closure $factory): mixed
    {
        $store = self::store();

        if (array_key_exists($key, $store->data)) {
            return $store->data[$key];
        }

        $value = $factory();
        self::set($key, $value);

        return $value;
    }

    /**
     * 仅在键不存在时写入
     *
     * @return bool 是否发生了写入
     */
    public static function add(string $key, mixed $value): bool
    {
        if (array_key_exists($key, self::store()->data)) {
            return false;
        }

        self::set($key, $value);

        return true;
    }

    /**
     * 取出并删除某个键
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $data = self::store()->data;

        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        self::delete($key);

        return $value;
    }

    /**
     * 数值自增
     *
     * @throws ContextException 现有值不是数字
     */
    public static function increment(string $key, int|float $step = 1): int|float
    {
        $current = self::store()->data[$key] ?? 0;

        if (!is_int($current) && !is_float($current)) {
            throw ContextException::typeMismatch($key, 'int|float', $current);
        }

        $value = $current + $step;
        self::set($key, $value);

        return $value;
    }

    /**
     * 数值自减
     *
     * @throws ContextException 现有值不是数字
     */
    public static function decrement(string $key, int|float $step = 1): int|float
    {
        return self::increment($key, -$step);
    }

    /**
     * 向数组型上下文值追加元素
     *
     * @throws ContextException 现有值不是数组
     */
    public static function push(string $key, mixed ...$values): void
    {
        $current = self::store()->data[$key] ?? [];

        if (!is_array($current)) {
            throw ContextException::typeMismatch($key, 'array', $current);
        }

        foreach ($values as $value) {
            $current[] = $value;
        }

        self::set($key, $current);
    }

    /**
     * 仅获取指定键组成的子集
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function only(array $keys): array
    {
        return array_intersect_key(self::store()->data, array_flip($keys));
    }

    /**
     * 排除指定键后的子集
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function except(array $keys): array
    {
        return array_diff_key(self::store()->data, array_flip($keys));
    }

    // ==================== 作用域控制 ====================

    /**
     * 在全新的空白上下文作用域中执行回调，结束后自动回滚
     *
     * @template T
     * @param callable(): T $callable 回调
     * @return T
     * @throws Throwable 回调抛出的异常会原样向外传递
     */
    public static function run(callable $callable): mixed
    {
        return self::runWith([], $callable);
    }

    /**
     * 在继承当前上下文的新作用域中执行回调，结束后自动回滚
     *
     * @template T
     * @param callable(): T $callable 回调
     * @return T
     * @throws Throwable
     */
    public static function fork(callable $callable): mixed
    {
        return self::runWith(self::store()->data, $callable);
    }

    /**
     * 在「事务作用域」内执行回调，结束后自动回滚到进入前的上下文快照
     *
     * 与 {@see self::run()}/{@see self::fork()}（创建隔离子作用域）不同，
     * 本方法在「当前上下文」中执行回调，允许读写外部可见的上下文，
     * 但无论回调成功、返回还是抛异常，离开作用域时都会恢复进入前的全部键值，
     * 从而避免临时写入污染调用方上下文。异常会原样向外传播。
     *
     * @template T
     * @param callable(): T $callable 回调
     * @return T
     * @throws Throwable 回调抛出的异常会原样向外传递
     */
    public static function transaction(callable $callable): mixed
    {
        $snapshot = self::copy();
        try {
            return $callable();
        } finally {
            self::restore($snapshot);
        }
    }

    /**
     * 在指定初始数据的新作用域中执行回调
     *
     * @template T
     * @param array<string, mixed> $initial  初始上下文
     * @param callable(): T        $callable 回调
     * @return T
     * @throws Throwable
     */
    public static function runWith(array $initial, callable $callable): mixed
    {
        $store = self::store();
        $depth = $store->push($initial);

        try {
            return $callable();
        } finally {
            $store->unwind($depth);
        }
    }

    /**
     * 在临时覆盖若干键的作用域中执行回调
     *
     * @template T
     * @param array<string, mixed> $values   临时覆盖的键值
     * @param callable(): T        $callable 回调
     * @return T
     * @throws Throwable
     */
    public static function with(array $values, callable $callable): mixed
    {
        return self::runWith([...self::store()->data, ...$values], $callable);
    }

    /**
     * 手动进入一个作用域，返回可关闭的句柄
     *
     * 适合中间件等无法用闭包包裹的场景；句柄析构时会自动回滚。
     *
     * @param array<string, mixed>|null $initial null 表示继承当前上下文
     */
    public static function enter(?array $initial = null): ContextScope
    {
        $store = self::store();
        $depth = $store->push($initial ?? $store->data);

        return new ContextScope($store, $depth);
    }

    /**
     * 把当前上下文快照绑定到回调上
     *
     * 用于向新协程/新纤程投递任务时继承父上下文：
     *
     * ```php
     * go(Context::bind(function () {
     *     // 这里可以读到父协程的 trace_id
     * }));
     * ```
     *
     * @param callable                  $callable 目标回调
     * @param array<string, mixed>|null $snapshot 指定快照，默认取当前上下文
     */
    public static function bind(callable $callable, ?array $snapshot = null): Closure
    {
        $snapshot ??= self::store()->data;

        return static function (mixed ...$args) use ($callable, $snapshot): mixed {
            return self::runWith($snapshot, static fn (): mixed => $callable(...$args));
        };
    }

    /**
     * 当前作用域嵌套深度
     */
    public static function depth(): int
    {
        return self::store()->depth();
    }

    // ==================== 监听器 ====================

    /**
     * 注册上下文变更监听器
     *
     * @param string  $key      键名，传入 Context::WILDCARD 可监听所有键
     * @param Closure $listener 监听器，签名为 (string $key, mixed $oldValue, mixed $newValue)
     * @return string 监听器 ID，可用于精确移除
     */
    public static function listen(string $key, Closure $listener): string
    {
        $id = 'l' . (++self::$listenerSeq);
        self::$listeners[$key][$id] = $listener;

        return $id;
    }

    /**
     * 移除监听器
     *
     * @param string      $key 键名
     * @param string|null $id  监听器 ID，为 null 时移除该键的全部监听器
     */
    public static function unlisten(string $key, ?string $id = null): void
    {
        if ($id === null) {
            unset(self::$listeners[$key]);

            return;
        }

        unset(self::$listeners[$key][$id]);

        if (self::$listeners[$key] === []) {
            unset(self::$listeners[$key]);
        }
    }

    /**
     * 已注册监听器的键列表
     *
     * @return list<string>
     */
    public static function listenedKeys(): array
    {
        return array_keys(self::$listeners);
    }

    // ==================== 运行时探测 ====================

    /**
     * 获取当前运行时（枚举）
     *
     * 注意：结果不做静态缓存，因为同一进程内可能先后处于协程内外。
     */
    public static function runtime(): Runtime
    {
        if (Fiber::getCurrent() !== null) {
            return Runtime::Fiber;
        }

        if (self::swooleLoaded() && self::swooleCid() > 0) {
            return Runtime::Swoole;
        }

        if (self::swowLoaded() && self::swowCurrent() !== null) {
            return Runtime::Swow;
        }

        if (self::isThreadEnvironment()) {
            return Runtime::Thread;
        }

        if (function_exists('pcntl_fork')) {
            return Runtime::Process;
        }

        return Runtime::Sync;
    }

    /**
     * 获取当前运行时类型字符串
     *
     * @return string RUNTIME_* 常量之一
     */
    public static function getRuntime(): string
    {
        return self::runtime()->value;
    }

    /**
     * 是否在协程/纤程环境中运行
     */
    public static function isCoroutine(): bool
    {
        return self::runtime()->isCoroutine();
    }

    /**
     * 是否在多线程环境中运行
     */
    public static function isThread(): bool
    {
        return self::runtime() === Runtime::Thread;
    }

    /**
     * 是否在多进程环境中运行
     */
    public static function isProcess(): bool
    {
        return self::runtime() === Runtime::Process;
    }

    /**
     * 当前执行单元是否为主执行单元（非协程/纤程）
     */
    public static function isMain(): bool
    {
        return self::currentScope() === null;
    }

    /**
     * 获取当前执行单元 ID（协程/纤程/线程/进程）
     */
    public static function getExecutionId(): int|string|null
    {
        return match (self::runtime()) {
            Runtime::Fiber => self::fiberId(),
            Runtime::Swoole => self::swooleCid(),
            Runtime::Swow => self::swowCoroutineId(),
            Runtime::Thread => self::getThreadId(),
            Runtime::Process => self::getProcessId(),
            Runtime::Sync => null,
        };
    }

    /**
     * 获取当前执行单元 ID（getExecutionId 的兼容别名）
     */
    public static function getCoroutineId(): int|string|null
    {
        return self::getExecutionId();
    }

    /**
     * 获取当前进程 ID
     */
    public static function getProcessId(): int
    {
        $pid = getmypid();

        return $pid === false ? -1 : $pid;
    }

    /**
     * 获取当前线程的稳定标识
     *
     * PHP 未提供公开的线程 ID API，这里返回一个进程内稳定、线程间唯一的不透明整数；
     * 非多线程环境返回 null。
     */
    public static function getThreadId(): ?int
    {
        if (!self::isThreadEnvironment()) {
            return null;
        }

        return self::$threadId ??= random_int(1, PHP_INT_MAX);
    }

    /**
     * 重置全部上下文状态（主要用于测试）
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$rootStore = null;
        self::$scopedStores = null;
        self::$listeners = [];
        self::$listenerSeq = 0;
        self::$processContexts = [];
        self::$threadId = null;
        self::$postFork = false;
    }

    // ==================== 多进程支持 ====================

    /**
     * 是否处于 fork 之后的子进程
     */
    public static function isPostFork(): bool
    {
        return self::$postFork;
    }

    /**
     * 在调用 pcntl_fork() 之前保存上下文快照
     */
    public static function prepareFork(): void
    {
        self::set(self::PARENT_PROCESS_ID, self::getProcessId());
        self::$processContexts[self::getProcessId()] = self::store()->data;
    }

    /**
     * 在 pcntl_fork() 之后的子进程中初始化上下文
     *
     * @param bool $inheritParentContext 是否继承父进程上下文
     */
    public static function afterFork(bool $inheritParentContext = true): void
    {
        $store = self::store();
        $parentPid = $store->data[self::PARENT_PROCESS_ID] ?? null;

        if ($inheritParentContext && is_int($parentPid) && isset(self::$processContexts[$parentPid])) {
            $store->data = self::$processContexts[$parentPid];
        } elseif (!$inheritParentContext) {
            $store->data = [];
        }

        $store->data[self::PROCESS_ID] = self::getProcessId();
        self::$postFork = true;
    }

    /**
     * fork 出子进程执行任务
     *
     * @param callable $task           任务回调，在子进程中执行
     * @param bool     $inheritContext 是否继承父进程上下文
     * @return int 父进程中返回子进程 PID（子进程执行完毕后直接退出，不会返回）
     * @throws ContextException pcntl 不可用或 fork 失败
     */
    public static function runInProcess(callable $task, bool $inheritContext = true): int
    {
        self::assertPcntl();
        self::prepareFork();

        /** @var int $pid */
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new ContextException('fork 失败');
        }

        if ($pid === 0) {
            self::afterFork($inheritContext);

            try {
                $task();
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        }

        return $pid;
    }

    /**
     * 等待指定子进程结束
     *
     * @param int $pid 子进程 PID
     * @return int 退出码，异常终止返回 -1
     * @throws ContextException pcntl 不可用
     */
    public static function waitProcess(int $pid): int
    {
        self::assertPcntl();

        $status = 0;
        pcntl_waitpid($pid, $status);

        return pcntl_wifexited($status) ? (int)pcntl_wexitstatus($status) : -1;
    }

    /**
     * 使用进程池并行执行多个任务并收集返回值
     *
     * 相比 v2 的实现，这里改用 stream_socket_pair（无需 sockets 扩展）、
     * 先读干管道再回收子进程，彻底规避大结果集导致的管道死锁。
     *
     * @param array<array-key, callable> $tasks          任务数组
     * @param int                        $maxProcesses   最大并发进程数
     * @param bool                       $inheritContext 是否继承父进程上下文
     * @param bool                       $throwOnError   子进程异常时是否抛出
     * @return array<array-key, mixed> 与 $tasks 键一一对应的结果
     * @throws ContextException
     */
    public static function parallelProcesses(
        array $tasks,
        int $maxProcesses = 4,
        bool $inheritContext = true,
        bool $throwOnError = true,
    ): array {
        self::assertPcntl();

        if ($maxProcesses < 1) {
            throw new ContextException('最大进程数必须大于 0');
        }

        self::prepareFork();

        /** @var array<array-key, mixed> $results */
        $results = [];
        /** @var array<array-key, string> $errors */
        $errors = [];
        /** @var array<int, array{key: array-key, stream: resource}> $running */
        $running = [];

        foreach ($tasks as $key => $task) {
            while (count($running) >= $maxProcesses) {
                self::collectProcess($running, $results, $errors);
            }

            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

            if ($pair === false) {
                throw new ContextException('创建进程间通信管道失败');
            }

            /** @var int $pid */
            $pid = pcntl_fork();

            if ($pid === -1) {
                fclose($pair[0]);
                fclose($pair[1]);

                throw new ContextException("fork 任务 {$key} 失败");
            }

            if ($pid === 0) {
                fclose($pair[0]);
                self::afterFork($inheritContext);

                try {
                    $payload = ['ok' => true, 'value' => $task()];
                } catch (Throwable $e) {
                    $payload = ['ok' => false, 'error' => $e->getMessage()];
                }

                fwrite($pair[1], serialize($payload));
                fclose($pair[1]);

                exit($payload['ok'] === true ? 0 : 1);
            }

            fclose($pair[1]);
            $running[$pid] = ['key' => $key, 'stream' => $pair[0]];
        }

        while ($running !== []) {
            self::collectProcess($running, $results, $errors);
        }

        if ($throwOnError && $errors !== []) {
            $detail = [];
            foreach ($errors as $key => $message) {
                $detail[] = "[{$key}] {$message}";
            }

            throw new ContextException('子进程任务执行失败: ' . implode('; ', $detail));
        }

        return $results;
    }

    // ==================== 多线程支持 ====================

    /**
     * 在新线程中运行任务（需要 parallel 扩展）
     *
     * 注意：pthreads 最高只支持 PHP 7.4，v3.0 起不再支持。
     *
     * @param callable $task           任务回调
     * @param bool     $inheritContext 是否继承当前线程上下文
     * @return object parallel\Future
     * @throws ContextException 未安装 parallel 扩展
     */
    public static function runInThread(callable $task, bool $inheritContext = true): object
    {
        if (!self::parallelLoaded()) {
            throw ContextException::missingExtension('parallel', '多线程功能');
        }

        $snapshot = $inheritContext ? self::store()->data : [];
        $bootstrap = self::parallelBootstrap();
        $runtime = $bootstrap === '' ? new \parallel\Runtime() : new \parallel\Runtime($bootstrap);

        /** @var object $future */
        $future = $runtime->run(static function () use ($task, $snapshot): mixed {
            Context::$inParallelThread = true;

            try {
                Context::restore($snapshot);

                return $task();
            } finally {
                Context::$inParallelThread = false;
            }
        });

        return $future;
    }

    /**
     * 使用线程池并行执行多个任务
     *
     * @param array<array-key, callable> $tasks          任务数组
     * @param int                        $maxThreads     最大并发线程数
     * @param bool                       $inheritContext 是否继承当前线程上下文
     * @return array<array-key, mixed>
     * @throws ContextException 未安装 parallel 扩展
     */
    public static function parallelThreads(array $tasks, int $maxThreads = 4, bool $inheritContext = true): array
    {
        if (!self::parallelLoaded()) {
            throw ContextException::missingExtension('parallel', '多线程功能');
        }

        if ($maxThreads < 1) {
            throw new ContextException('最大线程数必须大于 0');
        }

        $snapshot = $inheritContext ? self::store()->data : [];
        $results = [];

        /**
         * 有界线程池：每个 runtime 同一时刻只承载一个任务。
         * 槽位满时优先回收已完成的 runtime；若都在忙则阻塞等待最先提交的那个。
         *
         * @var list<array{key: array-key, runtime: \parallel\Runtime, future: \parallel\Future}> $slots
         */
        $slots = [];

        foreach ($tasks as $key => $task) {
            $runtime = null;

            if (count($slots) >= $maxThreads) {
                foreach ($slots as $i => $slot) {
                    /** @var \parallel\Future $future */
                    $future = $slot['future'];
                    if ($future->done()) {
                        $results[$slot['key']] = $future->value();
                        $runtime = $slot['runtime'];
                        unset($slots[$i]);
                        break;
                    }
                }

                if ($runtime === null) {
                    $i = array_key_first($slots);
                    \assert($i !== null);
                    $slot = $slots[$i];
                    /** @var \parallel\Future $future */
                    $future = $slot['future'];
                    $results[$slot['key']] = $future->value();
                    $runtime = $slot['runtime'];
                    unset($slots[$i]);
                }
            }

            if ($runtime === null) {
                $bootstrap = self::parallelBootstrap();
                $runtime = $bootstrap === '' ? new \parallel\Runtime() : new \parallel\Runtime($bootstrap);
            }

            $future = $runtime->run(static function () use ($task, $snapshot): mixed {
                Context::$inParallelThread = true;

                try {
                    Context::restore($snapshot);

                    return $task();
                } finally {
                    Context::$inParallelThread = false;
                }
            });

            $slots[] = ['key' => $key, 'runtime' => $runtime, 'future' => $future];
        }

        foreach ($slots as $slot) {
            $results[$slot['key']] = $slot['future']->value();
        }

        return $results;
    }

    // ==================== 序列化与分布式传递 ====================

    /**
     * 序列化上下文为 JSON 字符串
     *
     * @param list<string> $onlyKeys 仅序列化指定键，为空则全部
     * @throws ContextException 序列化失败
     */
    public static function toJson(array $onlyKeys = []): string
    {
        try {
            return json_encode(self::export($onlyKeys), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ContextException::serializeFailed($e->getMessage(), $e);
        }
    }

    /**
     * 从 JSON 字符串反序列化上下文
     *
     * @param string $json  JSON 字符串
     * @param bool   $merge true 合并到现有上下文，false 整体替换
     * @return array<string, mixed> 反序列化后的数据
     * @throws ContextException 反序列化失败
     */
    public static function fromJson(string $json, bool $merge = false): array
    {
        if (!json_validate($json)) {
            throw ContextException::unserializeFailed(json_last_error_msg());
        }

        /** @var mixed $data */
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw ContextException::unserializeFailed('无效的上下文数据格式');
        }

        /** @var array<string, mixed> $data */
        return self::import($data, $merge);
    }

    /**
     * 导出可序列化的上下文数据
     *
     * @param list<string> $onlyKeys 仅导出指定键
     * @return array<string, mixed>
     */
    public static function export(array $onlyKeys = []): array
    {
        $data = self::store()->data;

        if ($onlyKeys !== []) {
            $data = array_intersect_key($data, array_flip($onlyKeys));
        }

        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = ValueSerializer::encode($value);
        }

        return $result;
    }

    /**
     * 导入上下文数据
     *
     * @param array<string, mixed> $data  导入的数据
     * @param bool                 $merge 是否合并到现有上下文
     * @return array<string, mixed>
     */
    public static function import(array $data, bool $merge = false): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = ValueSerializer::decode($value);
        }

        if ($merge) {
            self::merge($result);
        } else {
            self::restore($result);
        }

        return $result;
    }

    // ==================== 分布式链路追踪 ====================

    /**
     * 开启一条新链路
     *
     * @param string|null $traceId 链路 ID，为空则按 W3C 规范自动生成
     * @param string|null $nodeId  当前节点 ID
     * @return string 链路 ID
     */
    public static function startTrace(?string $traceId = null, ?string $nodeId = null): string
    {
        $traceId ??= TraceContext::generateTraceId();

        self::set(self::TRACE_ID, $traceId);
        self::set(self::SPAN_ID, TraceContext::generateSpanId());
        self::set(self::TRACE_FLAGS, TraceContext::FLAG_SAMPLED);

        if ($nodeId !== null) {
            self::set(self::NODE_ID, $nodeId);
        }

        return $traceId;
    }

    /**
     * 创建子 Span
     *
     * @return string 新的 Span ID
     */
    public static function startSpan(): string
    {
        $parentSpanId = self::get(self::SPAN_ID);
        $newSpanId = TraceContext::generateSpanId();

        if (is_string($parentSpanId)) {
            self::set(self::PARENT_SPAN_ID, $parentSpanId);
        }

        self::set(self::SPAN_ID, $newSpanId);

        return $newSpanId;
    }

    /**
     * 获取追踪信息
     *
     * @return array{trace_id: string|null, span_id: string|null, parent_span_id: string|null, node_id: string|null}
     */
    public static function getTraceInfo(): array
    {
        $data = self::store()->data;

        $pick = static function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;

            return is_string($value) ? $value : null;
        };

        return [
            self::TRACE_ID => $pick(self::TRACE_ID),
            self::SPAN_ID => $pick(self::SPAN_ID),
            self::PARENT_SPAN_ID => $pick(self::PARENT_SPAN_ID),
            self::NODE_ID => $pick(self::NODE_ID),
        ];
    }

    /**
     * 当前链路是否被采样
     */
    public static function isSampled(): bool
    {
        $flags = self::get(self::TRACE_FLAGS);

        if (is_int($flags)) {
            return ($flags & TraceContext::FLAG_SAMPLED) === TraceContext::FLAG_SAMPLED;
        }

        return false;
    }

    /**
     * 设置采样标志
     */
    public static function setSampled(bool $sampled = true): void
    {
        $flags = self::get(self::TRACE_FLAGS);
        $flags = is_int($flags) ? $flags : 0;

        self::set(
            self::TRACE_FLAGS,
            $sampled ? ($flags | TraceContext::FLAG_SAMPLED) : ($flags & ~TraceContext::FLAG_SAMPLED)
        );
    }

    /**
     * 设置来源节点 ID
     */
    public static function setSourceNode(string $sourceNodeId): void
    {
        self::set(self::SOURCE_NODE_ID, $sourceNodeId);
    }

    /**
     * 设置关联 ID
     */
    public static function setCorrelationId(string $correlationId): void
    {
        self::set(self::CORRELATION_ID, $correlationId);
    }

    /**
     * 设置请求 ID
     */
    public static function setRequestId(string $requestId): void
    {
        self::set(self::REQUEST_ID, $requestId);
    }

    /**
     * 获取需要跨节点传递的上下文键
     *
     * @return list<string>
     */
    public static function getDistributedKeys(): array
    {
        return [
            self::TRACE_ID,
            self::SPAN_ID,
            self::PARENT_SPAN_ID,
            self::TRACE_FLAGS,
            self::TRACE_STATE,
            self::BAGGAGE,
            self::NODE_ID,
            self::SOURCE_NODE_ID,
            self::REQUEST_ID,
            self::CORRELATION_ID,
        ];
    }

    /**
     * 导出用于跨节点传递的上下文
     *
     * @return array<string, mixed>
     */
    public static function exportForDistributed(): array
    {
        return self::export(self::getDistributedKeys());
    }

    // ==================== W3C Trace Context ====================

    /**
     * 生成 W3C traceparent 头
     *
     * @return string|null 当前 trace_id / span_id 不符合 W3C 规范时返回 null
     */
    public static function toTraceparent(): ?string
    {
        $traceId = self::get(self::TRACE_ID);
        $spanId = self::get(self::SPAN_ID);

        if (!is_string($traceId) || !is_string($spanId)) {
            return null;
        }

        return TraceContext::build($traceId, $spanId, self::isSampled());
    }

    /**
     * 解析 W3C traceparent 并写入上下文
     *
     * 上游 span 会成为本地的 parent_span_id，并自动生成新的本地 span_id。
     *
     * @param string      $traceparent traceparent 头值
     * @param string|null $tracestate  tracestate 头值
     * @return bool 是否解析成功
     */
    public static function fromTraceparent(string $traceparent, ?string $tracestate = null): bool
    {
        $parsed = TraceContext::parse($traceparent);

        if ($parsed === null) {
            return false;
        }

        self::set(self::TRACE_ID, $parsed['trace_id']);
        self::set(self::PARENT_SPAN_ID, $parsed['span_id']);
        self::set(self::SPAN_ID, TraceContext::generateSpanId());
        self::set(self::TRACE_FLAGS, $parsed['flags']);

        if ($tracestate !== null && $tracestate !== '') {
            self::set(self::TRACE_STATE, $tracestate);
        }

        return true;
    }

    /**
     * 设置一条 baggage
     */
    public static function setBaggage(string $key, string $value): void
    {
        $entries = self::baggageEntries();
        $entries[$key] = $value;

        self::set(self::BAGGAGE, TraceContext::buildBaggage($entries));
    }

    /**
     * 读取 baggage
     *
     * @param string|null $key 为 null 时返回全部
     * @return array<string, string>|string|null
     */
    public static function getBaggage(?string $key = null): array|string|null
    {
        $entries = self::baggageEntries();

        return $key === null ? $entries : ($entries[$key] ?? null);
    }

    /**
     * 导出 W3C 标准链路头
     *
     * @return array<string, string>
     */
    public static function toW3CHeaders(): array
    {
        $headers = [];

        $traceparent = self::toTraceparent();
        if ($traceparent !== null) {
            $headers['traceparent'] = $traceparent;
        }

        $tracestate = self::get(self::TRACE_STATE);
        if (is_string($tracestate) && $tracestate !== '') {
            $headers['tracestate'] = $tracestate;
        }

        $baggage = self::get(self::BAGGAGE);
        if (is_string($baggage) && $baggage !== '') {
            $headers['baggage'] = $baggage;
        }

        return $headers;
    }

    /**
     * 从 W3C 标准链路头恢复上下文（头名大小写不敏感）
     *
     * @param array<string, string> $headers
     * @return bool 是否成功解析到 traceparent
     */
    public static function fromW3CHeaders(array $headers): bool
    {
        $normalized = self::normalizeHeaders($headers);

        $baggage = $normalized['baggage'] ?? null;
        if (is_string($baggage) && $baggage !== '') {
            self::set(self::BAGGAGE, $baggage);
        }

        $traceparent = $normalized['traceparent'] ?? null;
        if (!is_string($traceparent) || $traceparent === '') {
            return false;
        }

        return self::fromTraceparent($traceparent, $normalized['tracestate'] ?? null);
    }

    /**
     * 导出为 HTTP Headers（私有 X-Context-* 协议）
     *
     * @param string $prefix     Header 前缀
     * @param bool   $withW3C    是否同时输出 traceparent/tracestate/baggage 标准头
     * @return array<string, string>
     */
    public static function toHeaders(string $prefix = 'X-Context-', bool $withW3C = true): array
    {
        $headers = [];

        foreach (self::exportForDistributed() as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $headers[$prefix . str_replace('_', '-', ucwords($key, '_'))] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value === null => '',
                default => (string)$value,
            };
        }

        if ($withW3C) {
            $headers += self::toW3CHeaders();
        }

        return $headers;
    }

    /**
     * 从 HTTP Headers 导入上下文（头名大小写不敏感）
     *
     * 若同时存在 W3C traceparent，则优先采用 W3C 标准头。
     *
     * @param array<string, string> $headers HTTP 头
     * @param string                $prefix  私有头前缀
     */
    public static function fromHeaders(array $headers, string $prefix = 'X-Context-'): void
    {
        $normalized = self::normalizeHeaders($headers);
        $prefixLower = strtolower($prefix);
        $prefixLen = strlen($prefixLower);
        $data = [];

        foreach ($normalized as $name => $value) {
            if (!str_starts_with($name, $prefixLower)) {
                continue;
            }

            $key = str_replace('-', '_', substr($name, $prefixLen));

            if ($key === '') {
                continue;
            }

            $data[$key] = $key === self::TRACE_FLAGS ? (int)$value : $value;
        }

        if ($data !== []) {
            self::import($data, true);
        }

        if (isset($normalized['traceparent'])) {
            self::fromW3CHeaders($normalized);
        }
    }

    /**
     * 从任意来源的头部继续链路：优先 W3C，回退私有协议
     *
     * @param array<string, string> $headers
     */
    public static function continueTrace(array $headers, string $prefix = 'X-Context-'): void
    {
        self::fromHeaders($headers, $prefix);

        if (!self::has(self::TRACE_ID)) {
            self::startTrace();
        }
    }

    // ==================== 私有实现 ====================

    /**
     * 取得当前执行单元独占的存储
     */
    private static function store(): ContextStore
    {
        $scope = self::currentScope();

        if ($scope === null) {
            return self::$rootStore ??= new ContextStore();
        }

        $map = self::$scopedStores ??= new WeakMap();

        if (!isset($map[$scope])) {
            $map[$scope] = new ContextStore();
        }

        return $map[$scope];
    }

    /**
     * 解析当前执行单元对应的作用域对象
     *
     * 返回 null 表示处于主执行单元（同步 / 进程 / 线程根），使用根存储。
     */
    private static function currentScope(): ?object
    {
        $fiber = Fiber::getCurrent();

        if ($fiber !== null) {
            return $fiber;
        }

        if (self::swooleLoaded() && self::swooleCid() > 0) {
            /** @var object|null $ctx */
            $ctx = \Swoole\Coroutine::getContext();

            if (is_object($ctx)) {
                return $ctx;
            }
        }

        if (self::swowLoaded()) {
            return self::swowCurrent();
        }

        return null;
    }

    /**
     * 触发监听器（含通配符）
     */
    private static function triggerListener(string $key, mixed $oldValue, mixed $newValue): void
    {
        if (self::$listeners === []) {
            return;
        }

        $listeners = self::$listeners[$key] ?? [];

        if (isset(self::$listeners[self::WILDCARD])) {
            $listeners += self::$listeners[self::WILDCARD];
        }

        foreach ($listeners as $listener) {
            try {
                $listener($key, $oldValue, $newValue);
            } catch (Throwable) {
                // 监听器异常不应影响上下文写入主流程
            }
        }
    }

    /**
     * 回收一个已完成的子进程并读取其结果
     *
     * @param array<int, array{key: array-key, stream: resource}> $running
     * @param array<array-key, mixed>                             $results
     * @param array<array-key, string>                            $errors
     */
    private static function collectProcess(array &$running, array &$results, array &$errors): void
    {
        $pid = array_key_first($running);

        if ($pid === null) {
            return;
        }

        $entry = $running[$pid];
        unset($running[$pid]);

        $raw = stream_get_contents($entry['stream']);
        fclose($entry['stream']);

        $status = 0;
        pcntl_waitpid($pid, $status);

        if (!is_string($raw) || $raw === '') {
            $errors[$entry['key']] = '子进程未返回任何结果';
            $results[$entry['key']] = null;

            return;
        }

        /** @var mixed $payload */
        $payload = @unserialize($raw, ['allowed_classes' => true]);

        if (!is_array($payload) || !isset($payload['ok'])) {
            $errors[$entry['key']] = '子进程返回数据无法解析';
            $results[$entry['key']] = null;

            return;
        }

        if ($payload['ok'] === true) {
            $results[$entry['key']] = $payload['value'] ?? null;

            return;
        }

        $message = $payload['error'] ?? '未知错误';
        $errors[$entry['key']] = is_string($message) ? $message : '未知错误';
        $results[$entry['key']] = null;
    }

    /**
     * 断言 pcntl 可用
     *
     * @throws ContextException
     */
    private static function assertPcntl(): void
    {
        if (!function_exists('pcntl_fork')) {
            throw ContextException::missingExtension('pcntl', '多进程功能');
        }
    }

    /**
     * 是否处于多线程环境
     */
    private static function isThreadEnvironment(): bool
    {
        return self::$inParallelThread;
    }

    /**
     * 头部名归一化为小写
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }

    /**
     * 解析当前 baggage
     *
     * @return array<string, string>
     */
    private static function baggageEntries(): array
    {
        $raw = self::get(self::BAGGAGE);

        return is_string($raw) ? TraceContext::parseBaggage($raw) : [];
    }

    private static function fiberId(): ?int
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? null : spl_object_id($fiber);
    }

    private static function swooleLoaded(): bool
    {
        return self::$hasSwoole ??= extension_loaded('swoole') && class_exists('\Swoole\Coroutine');
    }

    private static function swowLoaded(): bool
    {
        return self::$hasSwow ??= extension_loaded('swow') && class_exists('\Swow\Coroutine');
    }

    private static function parallelLoaded(): bool
    {
        return self::$hasParallel ??= extension_loaded('parallel') && class_exists('\parallel\Runtime');
    }

    /**
     * 定位 Composer 自动加载文件，供 parallel 工作线程引导
     *
     * parallel 的工作线程不会继承主线程已注册的自动加载器，必须在创建
     * parallel\Runtime 时显式传入 autoload.php，否则 worker 内无法加载 Context 等类。
     *
     * 从当前文件向上逐级查找 vendor/autoload.php（同时兼容「包根目录」与
     * 「vendor/kode/context」两种安装形态），结果缓存复用。
     */
    private static function parallelBootstrap(): string
    {
        if (self::$parallelBootstrap !== null) {
            return self::$parallelBootstrap;
        }

        $dir = __DIR__;
        $path = '';

        while (dirname($dir) !== $dir) {
            $candidate = $dir . '/vendor/autoload.php';

            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }

            $dir = dirname($dir);
        }

        self::$parallelBootstrap = $path;

        return $path;
    }

    private static function swooleCid(): int
    {
        /** @var int $cid */
        $cid = \Swoole\Coroutine::getCid();

        return $cid;
    }

    /**
     * 当前 Swow 协程，主协程视为根作用域返回 null
     */
    private static function swowCurrent(): ?object
    {
        /** @var object|null $current */
        $current = \Swow\Coroutine::getCurrent();

        if (!is_object($current)) {
            return null;
        }

        /** @var object|null $main */
        $main = \Swow\Coroutine::getMain();

        return $current === $main ? null : $current;
    }

    private static function swowCoroutineId(): ?int
    {
        $current = self::swowCurrent();

        return $current === null ? null : spl_object_id($current);
    }
}
