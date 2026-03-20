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
