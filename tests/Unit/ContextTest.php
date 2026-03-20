<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use Kode\Context\ContextException;
use PHPUnit\Framework\TestCase;

/**
 * Context类的单元测试
 */
class ContextTest extends TestCase
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
     * 测试设置和获取值
     */
    public function testSetAndGet(): void
    {
        Context::set('key1', 'value1');
        $this->assertEquals('value1', Context::get('key1'));

        Context::set('key2', 123);
        $this->assertEquals(123, Context::get('key2'));

        Context::set('key3', ['a', 'b', 'c']);
        $this->assertEquals(['a', 'b', 'c'], Context::get('key3'));
    }

    /**
     * 测试获取不存在的键返回默认值
     */
    public function testGetWithDefault(): void
    {
        $this->assertNull(Context::get('nonexistent'));
        $this->assertEquals('default', Context::get('nonexistent', 'default'));
    }

    /**
     * 测试判断键是否存在
     */
    public function testHas(): void
    {
        Context::set('key1', 'value1');
        $this->assertTrue(Context::has('key1'));
        $this->assertFalse(Context::has('nonexistent'));
    }

    /**
     * 测试删除键
     */
    public function testDelete(): void
    {
        Context::set('key1', 'value1');
        $this->assertTrue(Context::has('key1'));

        Context::delete('key1');
        $this->assertFalse(Context::has('key1'));
        $this->assertNull(Context::get('key1'));
    }

    /**
     * 测试清空上下文
     */
    public function testClear(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');

        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));

        Context::clear();

        $this->assertFalse(Context::has('key1'));
        $this->assertFalse(Context::has('key2'));
        $this->assertEmpty(Context::copy());
    }

    /**
     * 测试复制上下文
     */
    public function testCopy(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 123);

        $copy = Context::copy();
        $this->assertIsArray($copy);
        $this->assertCount(2, $copy);
        $this->assertEquals('value1', $copy['key1']);
        $this->assertEquals(123, $copy['key2']);
    }

    /**
     * 测试获取所有键名
     */
    public function testKeys(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 123);
        Context::set('key3', ['a', 'b']);

        $keys = Context::keys();
        $this->assertIsArray($keys);
        $this->assertCount(3, $keys);
        $this->assertContains('key1', $keys);
        $this->assertContains('key2', $keys);
        $this->assertContains('key3', $keys);
    }

    /**
     * 测试获取上下文数量
     */
    public function testCount(): void
    {
        $this->assertEquals(0, Context::count());

        Context::set('key1', 'value1');
        $this->assertEquals(1, Context::count());

        Context::set('key2', 123);
        $this->assertEquals(2, Context::count());

        Context::delete('key1');
        $this->assertEquals(1, Context::count());

        Context::clear();
        $this->assertEquals(0, Context::count());
    }

    /**
     * 测试获取所有上下文数据
     */
    public function testAll(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 123);

        $all = Context::all();
        $this->assertIsArray($all);
        $this->assertCount(2, $all);
        $this->assertEquals('value1', $all['key1']);
        $this->assertEquals(123, $all['key2']);

        $copy = Context::copy();
        $this->assertEquals($all, $copy);
    }

    /**
     * 测试合并数组到上下文
     */
    public function testMerge(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 123);

        Context::merge([
            'key2' => 456,
            'key3' => 'new'
        ]);

        $this->assertEquals('value1', Context::get('key1'));
        $this->assertEquals(456, Context::get('key2'));
        $this->assertEquals('new', Context::get('key3'));
        $this->assertEquals(3, Context::count());

        Context::merge([
            'key1' => 'new_value1',
            'key4' => 'value4'
        ], false);

        $this->assertEquals('value1', Context::get('key1'));
        $this->assertEquals(456, Context::get('key2'));
        $this->assertEquals('new', Context::get('key3'));
        $this->assertEquals('value4', Context::get('key4'));
        $this->assertEquals(4, Context::count());
    }

    /**
     * 测试run方法创建隔离作用域
     */
    public function testRun(): void
    {
        Context::set('outer', 'outer_value');

        $result = Context::run(function () {
            Context::set('inner', 'inner_value');

            $this->assertTrue(Context::has('inner'));
            $this->assertEquals('inner_value', Context::get('inner'));

            $this->assertFalse(Context::has('outer'));

            return 'result';
        });

        $this->assertEquals('result', $result);

        $this->assertTrue(Context::has('outer'));
        $this->assertEquals('outer_value', Context::get('outer'));

        $this->assertFalse(Context::has('inner'));
    }

    /**
     * 测试嵌套run方法
     */
    public function testNestedRun(): void
    {
        Context::set('level0', 'level0_value');

        Context::run(function () {
            Context::set('level1', 'level1_value');

            Context::run(function () {
                Context::set('level2', 'level2_value');

                $this->assertTrue(Context::has('level2'));
                $this->assertFalse(Context::has('level1'));
                $this->assertFalse(Context::has('level0'));

                return 'level2_result';
            });

            $this->assertTrue(Context::has('level1'));
            $this->assertFalse(Context::has('level2'));
            $this->assertFalse(Context::has('level0'));
        });

        $this->assertTrue(Context::has('level0'));
        $this->assertFalse(Context::has('level1'));
        $this->assertFalse(Context::has('level2'));
    }

    /**
     * 测试fork方法继承上下文
     */
    public function testFork(): void
    {
        Context::set('outer', 'outer_value');
        Context::set('shared', 'outer_shared');

        $result = Context::fork(function () {
            $this->assertTrue(Context::has('outer'));
            $this->assertEquals('outer_value', Context::get('outer'));

            Context::set('inner', 'inner_value');
            Context::set('shared', 'inner_shared');

            $this->assertEquals('inner_shared', Context::get('shared'));

            return 'result';
        });

        $this->assertEquals('result', $result);

        $this->assertTrue(Context::has('outer'));
        $this->assertEquals('outer_value', Context::get('outer'));
        $this->assertEquals('outer_shared', Context::get('shared'));

        $this->assertFalse(Context::has('inner'));
    }

    /**
     * 测试restore方法
     */
    public function testRestore(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');

        $snapshot = Context::copy();

        Context::clear();
        Context::set('key3', 'value3');

        Context::restore($snapshot);

        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));
        $this->assertFalse(Context::has('key3'));
        $this->assertEquals('value1', Context::get('key1'));
        $this->assertEquals('value2', Context::get('key2'));
    }

    /**
     * 测试监听器功能
     */
    public function testListener(): void
    {
        $changes = [];

        Context::listen('test_key', function ($key, $oldValue, $newValue) use (&$changes) {
            $changes[] = ['key' => $key, 'old' => $oldValue, 'new' => $newValue];
        });

        Context::set('test_key', 'value1');
        $this->assertCount(1, $changes);
        $this->assertEquals('test_key', $changes[0]['key']);
        $this->assertNull($changes[0]['old']);
        $this->assertEquals('value1', $changes[0]['new']);

        Context::set('test_key', 'value2');
        $this->assertCount(2, $changes);
        $this->assertEquals('value1', $changes[1]['old']);
        $this->assertEquals('value2', $changes[1]['new']);

        Context::delete('test_key');
        $this->assertCount(3, $changes);
        $this->assertEquals('value2', $changes[2]['old']);
        $this->assertNull($changes[2]['new']);
    }

    /**
     * 测试移除监听器
     */
    public function testUnlisten(): void
    {
        $callCount = 0;

        Context::listen('test_key', function () use (&$callCount) {
            $callCount++;
        });

        Context::set('test_key', 'value1');
        $this->assertEquals(1, $callCount);

        Context::unlisten('test_key');

        Context::set('test_key', 'value2');
        $this->assertEquals(1, $callCount);
    }

    /**
     * 测试getOfType方法成功
     */
    public function testGetOfTypeSuccess(): void
    {
        $object = new \stdClass();
        $object->property = 'test';

        Context::set('object', $object);

        $result = Context::getOfType('object', \stdClass::class);
        $this->assertSame($object, $result);
    }

    /**
     * 测试getOfType方法键不存在
     */
    public function testGetOfTypeKeyNotFound(): void
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage("上下文键 'nonexistent' 不存在");

        Context::getOfType('nonexistent', \stdClass::class);
    }

    /**
     * 测试getOfType方法类型不匹配
     */
    public function testGetOfTypeTypeMismatch(): void
    {
        Context::set('string_value', 'not_an_object');

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage("上下文键 'string_value' 的值不是 stdClass 类型");

        Context::getOfType('string_value', \stdClass::class);
    }

    /**
     * 测试获取运行时类型
     */
    public function testGetRuntime(): void
    {
        $runtime = Context::getRuntime();

        $this->assertContains($runtime, [
            Context::RUNTIME_FIBER,
            Context::RUNTIME_SWOOLE,
            Context::RUNTIME_SWOW,
            Context::RUNTIME_THREAD,
            Context::RUNTIME_PROCESS,
            Context::RUNTIME_SYNC,
        ]);
    }

    /**
     * 测试isCoroutine方法
     */
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

    /**
     * 测试getCoroutineId方法
     */
    public function testGetCoroutineId(): void
    {
        $id = Context::getCoroutineId();
        $runtime = Context::getRuntime();

        // getCoroutineId() 现在是 getExecutionId() 的别名
        // 在协程环境下返回协程 ID，在进程环境下返回进程 ID
        if ($runtime === Context::RUNTIME_SYNC || $runtime === Context::RUNTIME_THREAD) {
            $this->assertNull($id);
        } else {
            $this->assertNotNull($id);
        }
    }

    /**
     * 测试run方法抛出异常时恢复上下文
     */
    public function testRunWithException(): void
    {
        Context::set('outer', 'outer_value');

        try {
            Context::run(function () {
                Context::set('inner', 'inner_value');
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('Test exception', $e->getMessage());
        }

        $this->assertTrue(Context::has('outer'));
        $this->assertEquals('outer_value', Context::get('outer'));
        $this->assertFalse(Context::has('inner'));
    }

    /**
     * 测试fork方法抛出异常时恢复上下文
     */
    public function testForkWithException(): void
    {
        Context::set('outer', 'outer_value');

        try {
            Context::fork(function () {
                Context::set('inner', 'inner_value');
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('Test exception', $e->getMessage());
        }

        $this->assertTrue(Context::has('outer'));
        $this->assertEquals('outer_value', Context::get('outer'));
        $this->assertFalse(Context::has('inner'));
    }

    /**
     * 测试null值存储
     */
    public function testNullValue(): void
    {
        Context::set('null_key', null);

        $this->assertTrue(Context::has('null_key'));
        $this->assertNull(Context::get('null_key'));
        $this->assertNull(Context::get('null_key', 'default'));
    }

    /**
     * 测试空字符串键
     */
    public function testEmptyStringKey(): void
    {
        Context::set('', 'empty_key_value');

        $this->assertTrue(Context::has(''));
        $this->assertEquals('empty_key_value', Context::get(''));
    }

    /**
     * 测试重置功能
     */
    public function testReset(): void
    {
        Context::set('key1', 'value1');
        Context::listen('key1', function () {});

        Context::reset();

        $this->assertFalse(Context::has('key1'));
        $this->assertEquals(0, Context::count());
    }
}
