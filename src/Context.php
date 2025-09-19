<?php

declare(strict_types=1);

namespace Kode\Context;

/**
 * 上下文管理类
 * 
 * 为多线程、多进程、协程（Swoole/Swow/Fiber）环境提供安全的请求上下文传递机制
 */
class Context
{
    /**
     * @var array<string, mixed>|null 上下文数据存储
     */
    private static ?array $local = null;

    /**
     * 设置上下文值
     *
     * @param string $key 键名
     * @param mixed $value 值
     */
    public static function set(string $key, mixed $value): void
    {
        self::initStorage();
        self::$local[$key] = $value;
    }

    /**
     * 获取上下文值
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::initStorage();
        return self::$local[$key] ?? $default;
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
        return isset(self::$local[$key]);
    }

    /**
     * 删除指定键
     *
     * @param string $key 键名
     */
    public static function delete(string $key): void
    {
        self::initStorage();
        unset(self::$local[$key]);
    }

    /**
     * 清空当前上下文所有数据
     */
    public static function clear(): void
    {
        self::initStorage();
        self::$local = [];
    }

    /**
     * 复制当前上下文为数组快照
     *
     * @return array<string, mixed>
     */
    public static function copy(): array
    {
        self::initStorage();
        return self::$local ?? [];
    }

    /**
     * 获取当前上下文中的所有键名
     *
     * @return array<string>
     */
    public static function keys(): array
    {
        self::initStorage();
        return array_keys(self::$local ?? []);
    }

    /**
     * 获取当前上下文中的键值对数量
     *
     * @return int
     */
    public static function count(): int
    {
        self::initStorage();
        return count(self::$local ?? []);
    }

    /**
     * 获取当前上下文中的所有数据
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::initStorage();
        return self::$local ?? [];
    }

    /**
     * 将数组合并到当前上下文中
     *
     * @param array<string, mixed> $data 要合并的数据
     * @param bool $overwrite 是否覆盖已存在的键，默认为true
     */
    public static function merge(array $data, bool $overwrite = true): void
    {
        self::initStorage();
        if ($overwrite) {
            self::$local = array_merge(self::$local ?? [], $data);
        } else {
            self::$local = array_merge($data, self::$local ?? []);
        }
    }

    /**
     * 在新的上下文作用域中执行callable，结束后自动回滚到之前的状态
     *
     * @param callable $callable 要执行的回调函数
     * @return mixed
     */
    public static function run(callable $callable): mixed
    {
        // 保存当前上下文
        $backup = self::$local;
        
        // 清空当前上下文
        self::$local = null;
        
        try {
            // 执行回调函数
            $result = $callable();
        } finally {
            // 恢复之前的上下文
            self::$local = $backup;
        }
        
        return $result;
    }

    /**
     * 初始化存储机制
     */
    private static function initStorage(): void
    {
        if (self::$local !== null) {
            return;
        }

        if (\PHP_VERSION_ID >= 80300 && class_exists(\Fiber::class)) {
            // PHP 8.3+ Fiber
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                // 使用反射来安全地调用getLocal方法
                try {
                    $reflector = new \ReflectionClass($fiber);
                    if ($reflector->hasMethod('getLocal')) {
                        $method = $reflector->getMethod('getLocal');
                        $method->setAccessible(true);
                        $localData = $method->invoke($fiber);
                        self::$local =& $localData['context'] ?? [];
                        return;
                    }
                } catch (\ReflectionException $e) {
                    // 如果反射失败，继续使用其他存储机制
                }
            }
        } elseif (extension_loaded('swoole')) {
            // Swoole
            $cid = \Swoole\Coroutine::getCid();
            if ($cid !== -1) {
                $ctx = \Swoole\Coroutine::getContext();
                self::$local =& $ctx['context'] ?? [];
                return;
            }
        } elseif (extension_loaded('swow')) {
            // Swow
            if (class_exists(\Swow\Coroutine::class)) {
                $co = \Swow\Coroutine::getCurrent();
                if ($co !== null) {
                    self::$local =& $co->getLocal()['context'] ?? [];
                    return;
                }
            }
        }
        
        // Sync mode or fallback
        if (!isset($GLOBALS['__context'])) {
            $GLOBALS['__context'] = [];
        }
        self::$local =& $GLOBALS['__context'];
    }
}