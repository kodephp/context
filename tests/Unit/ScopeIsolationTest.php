<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Fiber;
use Kode\Context\Context;
use Kode\Context\ContextScope;
use PHPUnit\Framework\TestCase;

/**
 * 执行单元隔离测试
 *
 * v2.x 中由于 $initialized 全局标志与错误的引用绑定，Fiber 之间的上下文实际上是完全共享的。
 * 本测试用于锁死 v3.0 修复后的隔离语义，防止回归。
 */
class ScopeIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::reset();
    }

    protected function tearDown(): void
    {
        Context::reset();
    }

    /**
     * 多个 Fiber 交叉执行时上下文互不污染
     */
    public function testFibersAreFullyIsolated(): void
    {
        Context::set('who', 'main');

        $seen = [];

        $fiber1 = new Fiber(function () use (&$seen): void {
            Context::set('who', 'fiber-1');
            Fiber::suspend();
            $seen['fiber1'] = Context::get('who');
        });

        $fiber2 = new Fiber(function () use (&$seen): void {
            Context::set('who', 'fiber-2');
            Fiber::suspend();
            $seen['fiber2'] = Context::get('who');
        });

        $fiber1->start();
        $fiber2->start();
        $fiber1->resume();
        $fiber2->resume();

        $this->assertSame('fiber-1', $seen['fiber1']);
        $this->assertSame('fiber-2', $seen['fiber2']);
        $this->assertSame('main', Context::get('who'), '主执行单元不应被 Fiber 污染');
    }

    /**
     * Fiber 默认拿到干净的上下文，不会看到主上下文
     */
    public function testFiberStartsWithCleanContext(): void
    {
        Context::set('secret', 'main-only');

        $fiber = new Fiber(function (): bool {
            return Context::has('secret');
        });

        $fiber->start();

        $this->assertFalse($fiber->getReturn());
        $this->assertTrue(Context::has('secret'));
    }

    /**
     * bind() 让新 Fiber 继承父上下文快照，且写入不回流
     */
    public function testBindInheritsSnapshotWithoutLeakingBack(): void
    {
        Context::set('trace_id', 'abc');
        Context::set('user_id', 7);

        $fiber = new Fiber(Context::bind(function (): array {
            $inherited = [Context::get('trace_id'), Context::get('user_id')];
            Context::set('user_id', 999);
            Context::set('local_only', true);

            return $inherited;
        }));

        $fiber->start();

        $this->assertSame(['abc', 7], $fiber->getReturn());
        $this->assertSame(7, Context::get('user_id'), '子执行单元的修改不应回流');
        $this->assertFalse(Context::has('local_only'));
    }

    /**
     * bind() 支持透传调用参数
     */
    public function testBindPassesArguments(): void
    {
        Context::set('factor', 3);

        /** @var int $factor */
        $factor = Context::get('factor');
        $bound = Context::bind(static fn (int $n): int => $n * $factor);

        $this->assertSame(12, $bound(4));
    }

    /**
     * 嵌套 Fiber 各自独立
     */
    public function testNestedFibersAreIsolated(): void
    {
        $result = [];

        $outer = new Fiber(function () use (&$result): void {
            Context::set('level', 'outer');

            $inner = new Fiber(function () use (&$result): void {
                $result['inner_sees_outer'] = Context::has('level');
                Context::set('level', 'inner');
                $result['inner'] = Context::get('level');
            });

            $inner->start();
            $result['outer_after'] = Context::get('level');
        });

        $outer->start();

        $this->assertFalse($result['inner_sees_outer']);
        $this->assertSame('inner', $result['inner']);
        $this->assertSame('outer', $result['outer_after']);
    }

    /**
     * Fiber 结束后上下文应被 WeakMap 自动回收
     */
    public function testContextIsReleasedAfterFiberFinishes(): void
    {
        $fiber = new Fiber(static function (): void {
            Context::set('temp', str_repeat('x', 1024));
        });

        $fiber->start();

        $this->assertTrue($fiber->isTerminated());

        unset($fiber);
        gc_collect_cycles();

        // 主上下文完全不受影响
        $this->assertFalse(Context::has('temp'));
        $this->assertSame(0, Context::count());
    }

    /**
     * enter() 返回的句柄可显式关闭
     */
    public function testEnterScopeCloseRestoresContext(): void
    {
        Context::set('base', 1);

        $scope = Context::enter(['scoped' => true]);

        $this->assertInstanceOf(ContextScope::class, $scope);
        $this->assertTrue(Context::get('scoped'));
        $this->assertFalse(Context::has('base'));

        $scope->close();

        $this->assertTrue($scope->isClosed());
        $this->assertSame(1, Context::get('base'));
        $this->assertFalse(Context::has('scoped'));
    }

    /**
     * enter() 默认继承当前上下文
     */
    public function testEnterInheritsByDefault(): void
    {
        Context::set('base', 1);

        $scope = Context::enter();
        $this->assertSame(1, Context::get('base'));
        Context::set('extra', 2);
        $scope->close();

        $this->assertFalse(Context::has('extra'));
    }

    /**
     * 句柄析构时自动回滚，重复 close 幂等
     */
    public function testScopeAutoClosesOnDestruct(): void
    {
        Context::set('base', 1);

        (function (): void {
            $scope = Context::enter(['tmp' => 'x']);
            $this->assertSame('x', Context::get('tmp'));
            unset($scope);
        })();

        gc_collect_cycles();

        $this->assertFalse(Context::has('tmp'));
        $this->assertSame(1, Context::get('base'));
    }

    /**
     * 未关闭的嵌套作用域会被外层一并回收，不会造成栈泄漏
     */
    public function testUnclosedNestedScopeIsUnwoundByOuter(): void
    {
        Context::set('base', 1);
        $this->assertSame(0, Context::depth());

        $outer = Context::enter(['a' => 1]);
        $leaked = Context::enter(['b' => 2]);
        $this->assertSame(2, Context::depth());

        $outer->close();

        $this->assertSame(0, Context::depth());
        $this->assertSame(1, Context::get('base'));

        // 先于外层销毁的内层句柄不应再改动上下文
        unset($leaked);
        gc_collect_cycles();
        $this->assertSame(1, Context::get('base'));
    }

    /**
     * runWith 使用指定初始数据
     */
    public function testRunWith(): void
    {
        Context::set('outer', 'o');

        $result = Context::runWith(['seed' => 's'], static function (): array {
            Context::set('added', 'a');

            return Context::all();
        });

        $this->assertSame(['seed' => 's', 'added' => 'a'], $result);
        $this->assertSame(['outer' => 'o'], Context::all());
    }

    /**
     * with 临时覆盖部分键
     */
    public function testWithTemporarilyOverrides(): void
    {
        Context::set('env', 'prod');
        Context::set('keep', 'yes');

        $inner = Context::with(['env' => 'test'], static fn (): array => [
            Context::get('env'),
            Context::get('keep'),
        ]);

        $this->assertSame(['test', 'yes'], $inner);
        $this->assertSame('prod', Context::get('env'));
    }

    /**
     * 主执行单元判定
     */
    public function testIsMain(): void
    {
        $this->assertTrue(Context::isMain());

        $fiber = new Fiber(static fn (): bool => Context::isMain());
        $fiber->start();

        $this->assertFalse($fiber->getReturn());
    }

    /**
     * Fiber 内的运行时识别为 fiber
     */
    public function testRuntimeInsideFiber(): void
    {
        $fiber = new Fiber(static fn (): string => Context::getRuntime());
        $fiber->start();

        $this->assertSame(Context::RUNTIME_FIBER, $fiber->getReturn());
        $this->assertNotSame(Context::RUNTIME_FIBER, Context::getRuntime());
    }

    /**
     * Fiber 内 run()/fork() 作用域栈互不干扰
     */
    public function testScopeStackIsPerExecutionUnit(): void
    {
        Context::set('main', true);

        $fiber = new Fiber(static function (): int {
            Context::set('f', 1);

            return Context::fork(static function (): int {
                Context::set('f', 2);

                return Context::depth();
            });
        });

        $fiber->start();

        $this->assertSame(1, $fiber->getReturn());
        $this->assertSame(0, Context::depth(), '主执行单元的作用域栈不应被 Fiber 影响');
    }
}
