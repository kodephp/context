<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use Kode\Context\ContextException;
use PHPUnit\Framework\TestCase;

/**
 * Context 多进程/多线程功能测试
 */
class ProcessThreadContextTest extends TestCase
{
    protected function setUp(): void
    {
        Context::reset();
    }

    protected function tearDown(): void
    {
        Context::reset();
    }

    public function testGetRuntime(): void
    {
        $runtime = Context::getRuntime();
        $validRuntimes = [
            Context::RUNTIME_FIBER,
            Context::RUNTIME_SWOOLE,
            Context::RUNTIME_SWOW,
            Context::RUNTIME_THREAD,
            Context::RUNTIME_PROCESS,
            Context::RUNTIME_SYNC,
        ];
        $this->assertContains($runtime, $validRuntimes);
    }

    public function testIsCoroutine(): void
    {
        $isCoroutine = Context::isCoroutine();
        $runtime = Context::getRuntime();
        $coroutineRuntimes = [Context::RUNTIME_FIBER, Context::RUNTIME_SWOOLE, Context::RUNTIME_SWOW];
        if (in_array($runtime, $coroutineRuntimes, true)) {
            $this->assertTrue($isCoroutine);
        } else {
            $this->assertFalse($isCoroutine);
        }
    }

    public function testIsThread(): void
    {
        $isThread = Context::isThread();
        $runtime = Context::getRuntime();
        if ($runtime === Context::RUNTIME_THREAD) {
            $this->assertTrue($isThread);
        } else {
            $this->assertFalse($isThread);
        }
    }

    public function testIsProcess(): void
    {
        $isProcess = Context::isProcess();
        $runtime = Context::getRuntime();
        if ($runtime === Context::RUNTIME_PROCESS) {
            $this->assertTrue($isProcess);
        } else {
            $this->assertFalse($isProcess);
        }
    }

    public function testGetExecutionId(): void
    {
        $id = Context::getExecutionId();
        $runtime = Context::getRuntime();
        if ($runtime === Context::RUNTIME_SYNC) {
            $this->assertNull($id);
        } else {
            $this->assertNotNull($id);
        }
    }

    public function testGetProcessId(): void
    {
        $pid = Context::getProcessId();
        $this->assertIsInt($pid);
        $this->assertGreaterThan(0, $pid);
    }

    public function testGetThreadId(): void
    {
        $tid = Context::getThreadId();
        $runtime = Context::getRuntime();
        if ($runtime === Context::RUNTIME_THREAD) {
            $this->assertNotNull($tid);
        } else {
            $this->assertNull($tid);
        }
    }

    public function testPrepareFork(): void
    {
        Context::set('key1', 'value1');
        Context::prepareFork();
        $this->assertTrue(Context::has(Context::PARENT_PROCESS_ID));
        $this->assertEquals(getmypid(), Context::get(Context::PARENT_PROCESS_ID));
    }

    public function testAfterForkWithInherit(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');
        Context::prepareFork();
        Context::afterFork(true);
        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));
        $this->assertEquals(getmypid(), Context::get(Context::PROCESS_ID));
    }

    public function testAfterForkWithoutInherit(): void
    {
        Context::set('key1', 'value1');
        Context::prepareFork();
        Context::afterFork(false);
        $this->assertFalse(Context::has('key1'));
        $this->assertEquals(getmypid(), Context::get(Context::PROCESS_ID));
    }

    public function testRunInProcessThrowsExceptionWithoutPcntl(): void
    {
        if (function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl 扩展已安装');
        }
        $this->expectException(ContextException::class);
        Context::runInProcess(fn() => true);
    }

    public function testParallelProcessesThrowsExceptionWithoutPcntl(): void
    {
        if (function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl 扩展已安装');
        }
        $this->expectException(ContextException::class);
        Context::parallelProcesses([fn() => true]);
    }

    public function testRunInThreadThrowsExceptionWithoutExtension(): void
    {
        if (class_exists(\Thread::class) || extension_loaded('parallel')) {
            $this->markTestSkipped('多线程扩展已安装');
        }
        $this->expectException(ContextException::class);
        Context::runInThread(fn() => true);
    }

    public function testParallelThreadsThrowsExceptionWithoutExtension(): void
    {
        if (class_exists(\Thread::class) || extension_loaded('parallel')) {
            $this->markTestSkipped('多线程扩展已安装');
        }
        $this->expectException(ContextException::class);
        Context::parallelThreads([fn() => true]);
    }

    /**
     * 正向：runInThread 在真实 parallel 线程中执行并返回 Future
     */
    public function testRunInThreadReturnsFutureInRealThread(): void
    {
        if (!extension_loaded('parallel')) {
            $this->markTestSkipped('parallel 扩展未安装');
        }

        $future = Context::runInThread(static fn (): int => 42);

        $this->assertInstanceOf(\parallel\Future::class, $future);
        $this->assertSame(42, $future->value());
    }

    /**
     * 正向：parallelThreads 有界线程池（并发数 < 任务数）必须全部正确执行
     *
     * 复现 v3.1.0 隐藏 bug：原实现用 count%max 轮询复用 runtime，
     * 在 maxThreads < 任务数时会向仍在忙的 runtime 再次 run() 抛 parallel\Runtime\Error。
     */
    public function testParallelThreadsBoundedPoolRunsAllTasks(): void
    {
        if (!extension_loaded('parallel')) {
            $this->markTestSkipped('parallel 扩展未安装');
        }

        $tasks = [];
        $expected = [];

        for ($i = 0; $i < 8; $i++) {
            $tasks["k$i"] = static function () use ($i): int {
                usleep(2000);

                return $i * 2;
            };
            $expected["k$i"] = $i * 2;
        }

        $results = Context::parallelThreads($tasks, 2, false);

        ksort($results);
        $this->assertSame($expected, $results);
    }

    /**
     * 正向：parallelProcesses 进程池（pcntl fork）必须全部正确执行
     */
    public function testParallelProcessesRunsAllTasks(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl 扩展未安装');
        }

        $tasks = [];
        $expected = [];

        for ($i = 0; $i < 6; $i++) {
            $tasks["p$i"] = static function () use ($i): int {
                return $i + 1;
            };
            $expected["p$i"] = $i + 1;
        }

        $results = Context::parallelProcesses($tasks, 2, false);

        ksort($results);
        $this->assertSame($expected, $results);
    }

    public function testRuntimeConstants(): void
    {
        $this->assertEquals('fiber', Context::RUNTIME_FIBER);
        $this->assertEquals('swoole', Context::RUNTIME_SWOOLE);
        $this->assertEquals('swow', Context::RUNTIME_SWOW);
        $this->assertEquals('thread', Context::RUNTIME_THREAD);
        $this->assertEquals('process', Context::RUNTIME_PROCESS);
        $this->assertEquals('sync', Context::RUNTIME_SYNC);
    }

    public function testContextConstants(): void
    {
        $this->assertEquals('trace_id', Context::TRACE_ID);
        $this->assertEquals('span_id', Context::SPAN_ID);
        $this->assertEquals('parent_span_id', Context::PARENT_SPAN_ID);
        $this->assertEquals('node_id', Context::NODE_ID);
        $this->assertEquals('process_id', Context::PROCESS_ID);
        $this->assertEquals('thread_id', Context::THREAD_ID);
        $this->assertEquals('parent_process_id', Context::PARENT_PROCESS_ID);
    }

    public function testResetClearsAllState(): void
    {
        Context::set('key1', 'value1');
        Context::prepareFork();
        Context::listen('key1', fn() => true);
        Context::reset();
        $this->assertFalse(Context::has('key1'));
        $this->assertEquals(0, Context::count());
    }
}
