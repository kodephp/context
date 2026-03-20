<?php

declare(strict_types=1);

namespace Kode\Context;

use Closure;
use Fiber;
use ReflectionClass;
use ReflectionException;
use Throwable;
use JsonSerializable;

/**
 * 上下文管理类
 *
 * 为多进程、多线程、协程（Swoole/Swow/Fiber）环境提供安全的请求上下文传递机制。
 * 支持 PHP 8.1+ 并兼容 PHP 8.5 新特性。
 * 支持分布式多机器部署场景的上下文序列化和传递。
 *
 * 支持的运行环境：
 * - 多进程（pcntl_fork、进程池）
 * - 多线程（ZTS + pthreads/parallel）
 * - 协程（Swoole、Swow、PHP Fiber）
 * - 同步模式
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
final class Context implements JsonSerializable
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
     * 当前进程/线程/协程 ID
     */
    private static int|string|null $executionId = null;

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
     * 进程级上下文存储（用于 fork 后继承）
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $processContexts = [];

    /**
     * 线程级上下文存储（用于 ZTS 环境）
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $threadContexts = [];

    /**
     * 是否在 fork 后状态
     */
    private static bool $postFork = false;

    /**
     * 分布式追踪相关键名
     */
    public const TRACE_ID = 'trace_id';
    public const SPAN_ID = 'span_id';
    public const PARENT_SPAN_ID = 'parent_span_id';
    public const NODE_ID = 'node_id';
    public const SOURCE_NODE_ID = 'source_node_id';
    public const REQUEST_ID = 'request_id';
    public const CORRELATION_ID = 'correlation_id';
    public const PROCESS_ID = 'process_id';
    public const THREAD_ID = 'thread_id';
    public const PARENT_PROCESS_ID = 'parent_process_id';

    /**
     * 运行时类型常量
     */
    public const RUNTIME_FIBER = 'fiber';
    public const RUNTIME_SWOOLE = 'swoole';
    public const RUNTIME_SWOW = 'swow';
    public const RUNTIME_THREAD = 'thread';
    public const RUNTIME_PROCESS = 'process';
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

        // 检查 Fiber
        if (PHP_VERSION_ID >= 80300 && class_exists(Fiber::class) && Fiber::getCurrent() !== null) {
            return self::$runtime = self::RUNTIME_FIBER;
        }

        // 检查 Swoole 协程
        if (extension_loaded('swoole') && self::isSwooleCoroutine()) {
            return self::$runtime = self::RUNTIME_SWOOLE;
        }

        // 检查 Swow 协程
        if (extension_loaded('swow') && self::isSwowCoroutine()) {
            return self::$runtime = self::RUNTIME_SWOW;
        }

        // 检查多线程环境 (ZTS + pthreads/parallel)
        if (self::isThreadEnvironment()) {
            return self::$runtime = self::RUNTIME_THREAD;
        }

        // 检查多进程环境
        if (self::isProcessEnvironment()) {
            return self::$runtime = self::RUNTIME_PROCESS;
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
        $runtime = self::getRuntime();
        return $runtime === self::RUNTIME_FIBER 
            || $runtime === self::RUNTIME_SWOOLE 
            || $runtime === self::RUNTIME_SWOW;
    }

    /**
     * 检查是否在多线程环境中运行
     *
     * @return bool
     */
    public static function isThread(): bool
    {
        return self::getRuntime() === self::RUNTIME_THREAD;
    }

    /**
     * 检查是否在多进程环境中运行
     *
     * @return bool
     */
    public static function isProcess(): bool
    {
        return self::getRuntime() === self::RUNTIME_PROCESS;
    }

    /**
     * 获取当前协程/Fiber/线程/进程 ID
     *
     * @return int|string|null
     */
    public static function getExecutionId(): int|string|null
    {
        if (self::$executionId !== null) {
            return self::$executionId;
        }

        $id = match (self::getRuntime()) {
            self::RUNTIME_FIBER => self::getFiberId(),
            self::RUNTIME_SWOOLE => self::getSwooleCoroutineId(),
            self::RUNTIME_SWOW => self::getSwowCoroutineId(),
            self::RUNTIME_THREAD => self::getThreadId(),
            self::RUNTIME_PROCESS => getmypid() ?: null,
            default => null,
        };

        return self::$executionId = $id;
    }

    /**
     * 获取当前协程/Fiber ID（兼容旧 API）
     *
     * @return int|string|null
     */
    public static function getCoroutineId(): int|string|null
    {
        return self::getExecutionId();
    }

    /**
     * 获取当前进程 ID
     *
     * @return int
     */
    public static function getProcessId(): int
    {
        return getmypid();
    }

    /**
     * 获取当前线程 ID（如果支持）
     *
     * @return int|null
     */
    public static function getThreadId(): ?int
    {
        // pthreads 扩展
        if (class_exists(\Thread::class) && method_exists(\Thread::class, 'getCurrentThreadId')) {
            return \Thread::getCurrentThreadId();
        }

        // parallel 扩展
        if (function_exists('parallel\\Runtime')) {
            // parallel 没有直接的线程 ID 获取方法
            return spl_object_id(new \stdClass());
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
        self::$executionId = null;
        self::$contextStack = [];
        self::$listeners = [];
        self::$processContexts = [];
        self::$threadContexts = [];
        self::$postFork = false;
    }

    // ==================== 多进程支持 ====================

    /**
     * 在 fork 后初始化子进程上下文
     *
     * 应该在 pcntl_fork() 后的子进程中调用
     *
     * @param bool $inheritParentContext 是否继承父进程的上下文，默认为 true
     */
    public static function afterFork(bool $inheritParentContext = true): void
    {
        $parentPid = self::get(self::PARENT_PROCESS_ID);

        if ($inheritParentContext && $parentPid !== null) {
            // 从父进程继承上下文
            $snapshot = self::$processContexts[$parentPid] ?? [];
            if (!empty($snapshot)) {
                self::$local = [...$snapshot];
            }
        } else {
            // 清空上下文，开始新的上下文
            self::$local = [];
        }

        // 设置当前进程 ID
        self::set(self::PROCESS_ID, getmypid());

        // 重置运行时检测
        self::$runtime = null;
        self::$executionId = null;
        self::$initialized = true;
        self::$postFork = true;
    }

    /**
     * 准备 fork 前的上下文快照
     *
     * 在调用 pcntl_fork() 之前调用此方法
     */
    public static function prepareFork(): void
    {
        self::initStorage();
        self::set(self::PARENT_PROCESS_ID, getmypid());

        // 保存当前上下文快照
        self::$processContexts[getmypid()] = self::$local;
    }

    /**
     * 在子进程中运行任务
     *
     * @template T
     * @param callable(): T $task              任务回调
     * @param bool          $inheritContext    是否继承父进程上下文
     * @return T|null 返回任务结果，如果 fork 失败返回 null
     */
    public static function runInProcess(callable $task, bool $inheritContext = true): mixed
    {
        if (!function_exists('pcntl_fork')) {
            throw new ContextException('pcntl 扩展未安装，无法使用多进程功能');
        }

        self::prepareFork();

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new ContextException('fork 失败');
        }

        if ($pid === 0) {
            // 子进程
            self::afterFork($inheritContext);
            try {
                $result = $task();
                exit(0);
            } catch (Throwable $e) {
                exit(1);
            }
        }

        // 父进程
        return null;
    }

    /**
     * 使用进程池执行多个任务
     *
     * @param array<callable> $tasks          任务数组
     * @param int             $maxProcesses   最大进程数
     * @param bool            $inheritContext 是否继承父进程上下文
     * @return array 任务结果数组
     */
    public static function parallelProcesses(array $tasks, int $maxProcesses = 4, bool $inheritContext = true): array
    {
        if (!function_exists('pcntl_fork')) {
            throw new ContextException('pcntl 扩展未安装，无法使用多进程功能');
        }

        self::prepareFork();
        $results = [];
        $pids = [];
        $pipes = [];

        foreach ($tasks as $key => $task) {
            // 创建管道用于进程间通信
            $pipe = [];
            if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pipe)) {
                throw new ContextException('创建 socket 对失败');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new ContextException("fork 任务 {$key} 失败");
            }

            if ($pid === 0) {
                // 子进程
                socket_close($pipe[0]);
                self::afterFork($inheritContext);

                try {
                    $result = $task();
                    $serialized = serialize($result);
                    socket_write($pipe[1], $serialized);
                    socket_close($pipe[1]);
                    exit(0);
                } catch (Throwable $e) {
                    $error = serialize(['__error__' => $e->getMessage()]);
                    socket_write($pipe[1], $error);
                    socket_close($pipe[1]);
                    exit(1);
                }
            }

            // 父进程
            socket_close($pipe[1]);
            $pids[$key] = $pid;
            $pipes[$key] = $pipe[0];

            // 控制并发进程数
            while (count($pids) >= $maxProcesses) {
                $status = 0;
                $finishedPid = pcntl_wait($status);
                if ($finishedPid > 0) {
                    foreach ($pids as $key => $pid) {
                        if ($pid === $finishedPid) {
                            // 读取结果
                            $data = '';
                            while ($chunk = socket_read($pipes[$key], 4096)) {
                                $data .= $chunk;
                            }
                            socket_close($pipes[$key]);
                            $results[$key] = unserialize($data);
                            unset($pids[$key], $pipes[$key]);
                            break;
                        }
                    }
                }
            }
        }

        // 等待所有子进程完成
        foreach ($pids as $key => $pid) {
            pcntl_waitpid($pid, $status);
            $data = '';
            while ($chunk = socket_read($pipes[$key], 4096)) {
                $data .= $chunk;
            }
            socket_close($pipes[$key]);
            $results[$key] = unserialize($data);
        }

        return $results;
    }

    // ==================== 多线程支持 ====================

    /**
     * 检查是否在多线程环境中
     *
     * @return bool
     */
    private static function isThreadEnvironment(): bool
    {
        // 检查 ZTS (Zend Thread Safety)
        if (!defined('ZEND_THREAD_SAFE') || !ZEND_THREAD_SAFE) {
            return false;
        }

        // 检查 pthreads 扩展
        if (class_exists(\Thread::class)) {
            return true;
        }

        // 检查 parallel 扩展
        if (extension_loaded('parallel')) {
            return true;
        }

        return false;
    }

    /**
     * 检查是否在多进程环境中
     *
     * @return bool
     */
    private static function isProcessEnvironment(): bool
    {
        // 如果有 pcntl 扩展且不是协程/线程环境，则认为是进程环境
        // 注意：这里不能调用 isCoroutine() 或 isThreadEnvironment()，因为它们会调用 getRuntime()
        // 而 getRuntime() 又会调用此方法，导致无限递归
        if (!function_exists('pcntl_fork')) {
            return false;
        }

        // 检查是否在协程中
        if (PHP_VERSION_ID >= 80300 && class_exists(Fiber::class) && Fiber::getCurrent() !== null) {
            return false;
        }

        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() !== -1) {
            return false;
        }

        if (extension_loaded('swow') && class_exists(\Swow\Coroutine::class) && \Swow\Coroutine::getCurrent() !== null) {
            return false;
        }

        // 检查是否在线程中
        if (self::isThreadEnvironment()) {
            return false;
        }

        return true;
    }

    /**
     * 在新线程中运行任务（需要 pthreads 或 parallel 扩展）
     *
     * @template T
     * @param callable(): T $task           任务回调
     * @param bool          $inheritContext 是否继承当前线程上下文
     * @return mixed 返回线程对象或 Future
     */
    public static function runInThread(callable $task, bool $inheritContext = true): mixed
    {
        $contextSnapshot = $inheritContext ? self::copy() : [];

        // pthreads 扩展
        if (class_exists(\Thread::class)) {
            $thread = new class($task, $contextSnapshot) extends \Thread {
                private $task;
                private array $context;
                public mixed $result;

                public function __construct(callable $task, array $context)
                {
                    $this->task = $task;
                    $this->context = $context;
                }

                public function run(): void
                {
                    Context::restore($this->context);
                    $this->result = ($this->task)();
                }
            };
            $thread->start();
            return $thread;
        }

        // parallel 扩展
        if (extension_loaded('parallel')) {
            $runtime = new \parallel\Runtime();
            return $runtime->run(function () use ($task, $contextSnapshot) {
                Context::restore($contextSnapshot);
                return $task();
            });
        }

        throw new ContextException('没有可用的多线程扩展（pthreads 或 parallel）');
    }

    /**
     * 使用线程池执行多个任务
     *
     * @param array<callable> $tasks          任务数组
     * @param int             $maxThreads     最大线程数
     * @param bool            $inheritContext 是否继承当前线程上下文
     * @return array 任务结果数组
     */
    public static function parallelThreads(array $tasks, int $maxThreads = 4, bool $inheritContext = true): array
    {
        // parallel 扩展
        if (extension_loaded('parallel')) {
            $contextSnapshot = $inheritContext ? self::copy() : [];
            $runtime = new \parallel\Runtime();
            $futures = [];

            foreach ($tasks as $key => $task) {
                $futures[$key] = $runtime->run(function () use ($task, $contextSnapshot) {
                    Context::restore($contextSnapshot);
                    return $task();
                });
            }

            $results = [];
            foreach ($futures as $key => $future) {
                $results[$key] = $future->value();
            }

            return $results;
        }

        // pthreads 扩展
        if (class_exists(\Thread::class)) {
            $contextSnapshot = $inheritContext ? self::copy() : [];
            $threads = [];

            foreach ($tasks as $key => $task) {
                $thread = new class($task, $contextSnapshot) extends \Thread {
                    private $task;
                    private array $context;
                    public mixed $result;

                    public function __construct(callable $task, array $context)
                    {
                        $this->task = $task;
                        $this->context = $context;
                    }

                    public function run(): void
                    {
                        Context::restore($this->context);
                        $this->result = ($this->task)();
                    }
                };
                $thread->start();
                $threads[$key] = $thread;

                // 控制并发线程数
                while (count($threads) >= $maxThreads) {
                    foreach ($threads as $k => $t) {
                        if (!$t->isRunning()) {
                            $t->join();
                            unset($threads[$k]);
                            break;
                        }
                    }
                    usleep(1000);
                }
            }

            // 等待所有线程完成
            $results = [];
            foreach ($threads as $key => $thread) {
                $thread->join();
                $results[$key] = $thread->result;
            }

            return $results;
        }

        throw new ContextException('没有可用的多线程扩展（pthreads 或 parallel）');
    }

    // ==================== 分布式支持 ====================

    /**
     * 序列化上下文为 JSON 字符串
     *
     * 用于分布式系统中跨节点传递上下文
     *
     * @param array<string> $onlyKeys 仅序列化指定的键，为空则序列化全部
     * @return string JSON 字符串
     * @throws ContextException 如果序列化失败
     */
    public static function toJson(array $onlyKeys = []): string
    {
        self::initStorage();
        $data = $onlyKeys === [] ? self::$local : array_intersect_key(
            self::$local,
            array_flip($onlyKeys)
        );

        $serializable = [];
        foreach ($data as $key => $value) {
            $serializable[$key] = self::serializeValue($value);
        }

        try {
            $json = json_encode($serializable, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ContextException('上下文序列化失败: ' . $e->getMessage(), 0, $e);
        }

        return $json;
    }

    /**
     * 从 JSON 字符串反序列化上下文
     *
     * @param string $json      JSON 字符串
     * @param bool   $merge     是否合并到现有上下文，false 则替换
     * @return array<string, mixed> 反序列化后的数据
     * @throws ContextException 如果反序列化失败
     */
    public static function fromJson(string $json, bool $merge = false): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ContextException('上下文反序列化失败: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new ContextException('无效的上下文数据格式');
        }

        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = self::unserializeValue($value);
        }

        if ($merge) {
            self::merge($result);
        } else {
            self::restore($result);
        }

        return $result;
    }

    /**
     * 导出可序列化的上下文数据
     *
     * @param array<string> $onlyKeys 仅导出指定的键
     * @return array<string, mixed>
     */
    public static function export(array $onlyKeys = []): array
    {
        self::initStorage();
        $data = $onlyKeys === [] ? self::$local : array_intersect_key(
            self::$local,
            array_flip($onlyKeys)
        );

        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = self::serializeValue($value);
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
            $result[$key] = self::unserializeValue($value);
        }

        if ($merge) {
            self::merge($result);
        } else {
            self::restore($result);
        }

        return $result;
    }

    /**
     * 创建分布式追踪上下文
     *
     * @param string|null $traceId 追踪ID，为空则自动生成
     * @param string|null $nodeId   当前节点ID
     * @return string 生成的 Trace ID
     */
    public static function startTrace(?string $traceId = null, ?string $nodeId = null): string
    {
        $traceId = $traceId ?? self::generateTraceId();
        $spanId = self::generateSpanId();

        self::set(self::TRACE_ID, $traceId);
        self::set(self::SPAN_ID, $spanId);

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
        $newSpanId = self::generateSpanId();

        if ($parentSpanId !== null) {
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
        $traceId = self::get(self::TRACE_ID);
        $spanId = self::get(self::SPAN_ID);
        $parentSpanId = self::get(self::PARENT_SPAN_ID);
        $nodeId = self::get(self::NODE_ID);

        return [
            self::TRACE_ID => is_string($traceId) ? $traceId : null,
            self::SPAN_ID => is_string($spanId) ? $spanId : null,
            self::PARENT_SPAN_ID => is_string($parentSpanId) ? $parentSpanId : null,
            self::NODE_ID => is_string($nodeId) ? $nodeId : null,
        ];
    }

    /**
     * 设置来源节点信息（用于分布式调用）
     *
     * @param string $sourceNodeId 来源节点ID
     */
    public static function setSourceNode(string $sourceNodeId): void
    {
        self::set(self::SOURCE_NODE_ID, $sourceNodeId);
    }

    /**
     * 设置关联ID（用于请求关联）
     *
     * @param string $correlationId 关联ID
     */
    public static function setCorrelationId(string $correlationId): void
    {
        self::set(self::CORRELATION_ID, $correlationId);
    }

    /**
     * 设置请求ID
     *
     * @param string $requestId 请求ID
     */
    public static function setRequestId(string $requestId): void
    {
        self::set(self::REQUEST_ID, $requestId);
    }

    /**
     * 获取分布式传递所需的上下文键
     *
     * @return array<string>
     */
    public static function getDistributedKeys(): array
    {
        return [
            self::TRACE_ID,
            self::SPAN_ID,
            self::PARENT_SPAN_ID,
            self::NODE_ID,
            self::SOURCE_NODE_ID,
            self::REQUEST_ID,
            self::CORRELATION_ID,
        ];
    }

    /**
     * 导出分布式追踪上下文（用于跨节点传递）
     *
     * @return array<string, mixed>
     */
    public static function exportForDistributed(): array
    {
        return self::export(self::getDistributedKeys());
    }

    /**
     * 导出为 HTTP Headers 格式
     *
     * @param string $prefix Header 前缀，默认 'X-Context-'
     * @return array<string, string>
     */
    public static function toHeaders(string $prefix = 'X-Context-'): array
    {
        $headers = [];
        $data = self::exportForDistributed();

        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $headers[$prefix . str_replace('_', '-', ucwords($key, '_'))] = (string)$value;
            }
        }

        return $headers;
    }

    /**
     * 从 HTTP Headers 导入上下文
     *
     * @param array<string, string> $headers HTTP Headers
     * @param string                $prefix  Header 前缀
     */
    public static function fromHeaders(array $headers, string $prefix = 'X-Context-'): void
    {
        $data = [];
        $prefixLen = strlen($prefix);

        foreach ($headers as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $key = strtolower(str_replace('-', '_', substr($name, $prefixLen)));
                $data[$key] = $value;
            }
        }

        self::import($data, true);
    }

    /**
     * 实现 JsonSerializable 接口
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return self::export();
    }

    // ==================== 私有方法 ====================

    /**
     * 初始化存储机制
     */
    private static function initStorage(): void
    {
        if (self::$initialized) {
            return;
        }

        // 优先检查 Fiber
        if (PHP_VERSION_ID >= 80300 && class_exists(Fiber::class)) {
            $fiber = Fiber::getCurrent();
            if ($fiber !== null) {
                self::initFiberStorage($fiber);
                self::$initialized = true;
                return;
            }
        }

        // 检查 Swoole 协程
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

        // 检查 Swow 协程
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

        // 检查多线程环境
        if (self::isThreadEnvironment()) {
            self::initThreadStorage();
            self::$initialized = true;
            return;
        }

        // 默认使用全局存储
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
     * 初始化线程存储
     */
    private static function initThreadStorage(): void
    {
        $threadId = self::getThreadId();

        if ($threadId === null) {
            return;
        }

        if (!isset(self::$threadContexts[$threadId])) {
            self::$threadContexts[$threadId] = [];
        }

        self::$local = &self::$threadContexts[$threadId];
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
     * 获取 Fiber ID
     */
    private static function getFiberId(): ?int
    {
        $fiber = Fiber::getCurrent();
        return $fiber !== null ? spl_object_id($fiber) : null;
    }

    /**
     * 获取 Swoole 协程 ID
     */
    private static function getSwooleCoroutineId(): int
    {
        /** @phpstan-ignore-next-line */
        return \Swoole\Coroutine::getCid();
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

    /**
     * 序列化单个值
     */
    private static function serializeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(self::serializeValue(...), $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return [
                '__type__' => 'datetime',
                'value' => $value->format(\DateTimeInterface::ATOM),
            ];
        }

        if ($value instanceof \BackedEnum) {
            return [
                '__type__' => 'enum',
                'class' => get_class($value),
                'value' => $value->value,
            ];
        }

        if (is_object($value)) {
            if ($value instanceof JsonSerializable) {
                return [
                    '__type__' => 'json_serializable',
                    'class' => get_class($value),
                    'value' => $value->jsonSerialize(),
                ];
            }

            return [
                '__type__' => 'object',
                'class' => get_class($value),
            ];
        }

        if (is_resource($value)) {
            return [
                '__type__' => 'resource',
                'type' => get_resource_type($value),
            ];
        }

        return $value;
    }

    /**
     * 反序列化单个值
     */
    private static function unserializeValue(mixed $value): mixed
    {
        if (!is_array($value) || !isset($value['__type__'])) {
            if (is_array($value)) {
                return array_map(self::unserializeValue(...), $value);
            }
            return $value;
        }

        return match ($value['__type__']) {
            'datetime' => new \DateTimeImmutable($value['value']),
            'enum' => class_exists($value['class'])
                ? ($value['class'])::from($value['value'])
                : $value['value'],
            'object', 'json_serializable' => $value['value'] ?? null,
            'resource' => null,
            default => $value['value'] ?? $value,
        };
    }

    /**
     * 生成 Trace ID
     */
    private static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 生成 Span ID
     */
    private static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
