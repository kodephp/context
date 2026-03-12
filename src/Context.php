<?php

declare(strict_types=1);

namespace Kode\Context;

use Closure;
use Fiber;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * 上下文管理类
 *
 * 为多线程、多进程、协程（Swoole/Swow/Fiber）环境提供安全的请求上下文传递机制。
 * 支持 PHP 8.1+ 并兼容 PHP 8.5 新特性。
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
final class Context
{
    /**
     * 上下文数据存储
     *
     * @var array<string, mixed>
     */
    private static array $local = [];

    /**
     * 存储是否已初始化标志
     */
    private static bool $initialized = false;

    /**
     * 当前运行时类型
     */
    private static ?string $runtime = null;

    /**
     * 上下文栈，用于嵌套 run() 调用
     *
     * @var array<int, array<string, mixed>|null>
     */
    private static array $contextStack = [];

    /**
     * 上下文变更监听器
     *
     * @var array<string, array<Closure>>
     */
    private static array $listeners = [];

    /**
     * 运行时类型常量
     */
    public const RUNTIME_FIBER = 'fiber';
    public const RUNTIME_SWOOLE = 'swoole';
    public const RUNTIME_SWOW = 'swow';
    public const RUNTIME_SYNC = 'sync';

    /**
     * 私有构造函数，防止实例化
     */
    private function __construct()
    {
    }

    /**
     * 设置上下文值
     *
     * @param string $key   键名
     * @param mixed  $value 值
     */
    public static function set(string $key, mixed $value): void
    {
        self::initStorage();
        $oldValue = self::$local[$key] ?? null;
        self::$local[$key] = $value;
        self::triggerListener($key, $oldValue, $value);
    }

    /**
     * 获取上下文值
     *
     * @template T
     * @param string $key     键名
     * @param T      $default 默认值
     * @return T|mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::initStorage();
        return array_key_exists($key, self::$local) ? self::$local[$key] : $default;
    }

    /**
     * 获取上下文值并断言类型
     *
     * @template T
     * @param string $key     键名
     * @param class-string<T> $type 期望的类型
     * @return T
     * @throws ContextException 如果值不存在或类型不匹配
     */
    public static function getOfType(string $key, string $type): mixed
    {
        $value = self::get($key);
        if ($value === null) {
            throw new ContextException("上下文键 '{$key}' 不存在");
        }
        if (!($value instanceof $type)) {
            throw new ContextException(
                "上下文键 '{$key}' 的值不是 {$type} 类型，实际类型为 " . get_debug_type($value)
            );
        }
        return $value;
    }

    /**
     * 判断键是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::initStorage();
        return array_key_exists($key, self::$local);
    }

    /**
     * 删除指定键
     *
     * @param string $key 键名
     */
    public static function delete(string $key): void
    {
        self::initStorage();
        if (array_key_exists($key, self::$local)) {
            $oldValue = self::$local[$key];
            unset(self::$local[$key]);
            self::triggerListener($key, $oldValue, null);
        }
    }

    /**
     * 清空当前上下文所有数据
     */
    public static function clear(): void
    {
        self::initStorage();
        $oldData = self::$local;
        self::$local = [];
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
        self::initStorage();
        return self::$local;
    }

    /**
     * 获取当前上下文中的所有键名
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        self::initStorage();
        return array_keys(self::$local);
    }

    /**
     * 获取当前上下文中的键值对数量
     *
     * @return int
     */
    public static function count(): int
    {
        self::initStorage();
        return count(self::$local);
    }

    /**
     * 获取当前上下文中的所有数据
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::initStorage();
        return self::$local;
    }

    /**
     * 将数组合并到当前上下文中
     *
     * @param array<string, mixed> $data      要合并的数据
     * @param bool                 $overwrite 是否覆盖已存在的键，默认为true
     */
    public static function merge(array $data, bool $overwrite = true): void
    {
        self::initStorage();
        foreach ($data as $key => $value) {
            if ($overwrite || !array_key_exists($key, self::$local)) {
                $oldValue = self::$local[$key] ?? null;
                self::$local[$key] = $value;
                self::triggerListener($key, $oldValue, $value);
            }
        }
    }

    /**
     * 在新的上下文作用域中执行callable，结束后自动回滚到之前的状态
     *
     * @template T
     * @param callable(): T $callable 要执行的回调函数
     * @return T
     * @throws Throwable
     */
    public static function run(callable $callable): mixed
    {
        self::initStorage();

        $backup = self::$local;
        self::$contextStack[] = $backup;
        self::$local = [];
        self::$initialized = true;

        try {
            return $callable();
        } finally {
            array_pop(self::$contextStack);
            self::$local = $backup;
        }
    }

    /**
     * 在继承当前上下文的新作用域中执行callable
     *
     * @template T
     * @param callable(): T $callable 要执行的回调函数
     * @return T
     * @throws Throwable
     */
    public static function fork(callable $callable): mixed
    {
        self::initStorage();

        $backup = self::$local;
        self::$contextStack[] = $backup;
        self::$local = [...$backup];
        self::$initialized = true;

        try {
            return $callable();
        } finally {
            array_pop(self::$contextStack);
            self::$local = $backup;
        }
    }

    /**
     * 从快照恢复上下文
     *
     * @param array<string, mixed> $snapshot 上下文快照
     */
    public static function restore(array $snapshot): void
    {
        self::initStorage();
        $oldData = self::$local;
        self::$local = $snapshot;
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

    /**
     * 注册上下文变更监听器
     *
     * @param string  $key      监听的键名
     * @param Closure $listener 监听器函数，接收参数：($key, $oldValue, $newValue)
     */
    public static function listen(string $key, Closure $listener): void
    {
        if (!isset(self::$listeners[$key])) {
            self::$listeners[$key] = [];
        }
        self::$listeners[$key][] = $listener;
    }

    /**
     * 移除上下文变更监听器
     *
     * @param string $key 监听的键名
     */
    public static function unlisten(string $key): void
    {
        unset(self::$listeners[$key]);
    }

    /**
     * 获取当前运行时类型
     *
     * @return string 返回 Runtime 常量之一
     */
    public static function getRuntime(): string
    {
        if (self::$runtime !== null) {
            return self::$runtime;
        }

        if (PHP_VERSION_ID >= 80300 && class_exists(Fiber::class) && Fiber::getCurrent() !== null) {
            return self::$runtime = self::RUNTIME_FIBER;
        }

        if (extension_loaded('swoole') && self::isSwooleCoroutine()) {
            return self::$runtime = self::RUNTIME_SWOOLE;
        }

        if (extension_loaded('swow') && self::isSwowCoroutine()) {
            return self::$runtime = self::RUNTIME_SWOW;
        }

        return self::$runtime = self::RUNTIME_SYNC;
    }

    /**
     * 检查是否在协程/Fiber环境中运行
     *
     * @return bool
     */
    public static function isCoroutine(): bool
    {
        return self::getRuntime() !== self::RUNTIME_SYNC;
    }

    /**
     * 获取当前协程/Fiber ID
     *
     * @return int|string|null 返回协程ID，同步模式下返回null
     */
    public static function getCoroutineId(): int|string|null
    {
        $runtime = self::getRuntime();
        if ($runtime === self::RUNTIME_FIBER) {
            $fiber = Fiber::getCurrent();
            return $fiber !== null ? spl_object_id($fiber) : null;
        }
        if ($runtime === self::RUNTIME_SWOOLE) {
            /** @phpstan-ignore-next-line */
            return \Swoole\Coroutine::getCid();
        }
        if ($runtime === self::RUNTIME_SWOW) {
            return self::getSwowCoroutineId();
        }
        return null;
    }

    /**
     * 重置上下文状态（主要用于测试）
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$local = [];
        self::$initialized = false;
        self::$runtime = null;
        self::$contextStack = [];
        self::$listeners = [];
    }

    /**
     * 初始化存储机制
     */
    private static function initStorage(): void
    {
        if (self::$initialized) {
            return;
        }

        if (PHP_VERSION_ID >= 80300 && class_exists(Fiber::class)) {
            $fiber = Fiber::getCurrent();
            if ($fiber !== null) {
                self::initFiberStorage($fiber);
                self::$initialized = true;
                return;
            }
        }

        if (extension_loaded('swoole')) {
            $cid = \Swoole\Coroutine::getCid();
            if ($cid !== -1) {
                $ctx = \Swoole\Coroutine::getContext();
                if (!isset($ctx['context'])) {
                    $ctx['context'] = [];
                }
                self::$local = &$ctx['context'];
                self::$initialized = true;
                return;
            }
        }

        if (extension_loaded('swow') && class_exists(\Swow\Coroutine::class)) {
            $co = \Swow\Coroutine::getCurrent();
            if ($co !== null) {
                $local = $co->getLocal();
                if (!isset($local['context'])) {
                    $local['context'] = [];
                }
                self::$local = &$local['context'];
                self::$initialized = true;
                return;
            }
        }

        if (!isset($GLOBALS['__kode_context'])) {
            $GLOBALS['__kode_context'] = [];
        }
        self::$local = &$GLOBALS['__kode_context'];
        self::$initialized = true;
    }

    /**
     * 初始化 Fiber 存储
     *
     * PHP 8.3+ 的 Fiber::getLocal() 方法需要通过反射调用
     *
     * @param Fiber $fiber 当前 Fiber 实例
     */
    private static function initFiberStorage(Fiber $fiber): void
    {
        try {
            $reflector = new ReflectionClass($fiber);
            if ($reflector->hasMethod('getLocal')) {
                $method = $reflector->getMethod('getLocal');
                $method->setAccessible(true);
                /** @var array<string, mixed> $localData */
                $localData = $method->invoke($fiber);
                if (!isset($localData['context'])) {
                    $localData['context'] = [];
                }
                self::$local = &$localData['context'];
                return;
            }
        } catch (ReflectionException) {
        }

        if (!isset($GLOBALS['__kode_fiber_context'])) {
            $GLOBALS['__kode_fiber_context'] = [];
        }
        $fiberId = spl_object_id($fiber);
        if (!isset($GLOBALS['__kode_fiber_context'][$fiberId])) {
            $GLOBALS['__kode_fiber_context'][$fiberId] = [];
        }
        self::$local = &$GLOBALS['__kode_fiber_context'][$fiberId];
    }

    /**
     * 检查是否在 Swoole 协程中
     */
    private static function isSwooleCoroutine(): bool
    {
        return \Swoole\Coroutine::getCid() !== -1;
    }

    /**
     * 检查是否在 Swow 协程中
     */
    private static function isSwowCoroutine(): bool
    {
        return class_exists(\Swow\Coroutine::class) && \Swow\Coroutine::getCurrent() !== null;
    }

    /**
     * 获取 Swow 协程 ID
     */
    private static function getSwowCoroutineId(): int
    {
        $co = \Swow\Coroutine::getCurrent();
        return $co !== null ? spl_object_id($co) : -1;
    }

    /**
     * 触发上下文变更监听器
     */
    private static function triggerListener(string $key, mixed $oldValue, mixed $newValue): void
    {
        if (!isset(self::$listeners[$key])) {
            return;
        }

        foreach (self::$listeners[$key] as $listener) {
            try {
                $listener($key, $oldValue, $newValue);
            } catch (Throwable) {
            }
        }
    }
}
