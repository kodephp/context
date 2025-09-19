<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use PHPUnit\Framework\TestCase;

/**
 * Context类的单元测试
 */
class ContextTest extends TestCase
{
    protected function setUp(): void
    {
        Context::clear();
    }

    protected function tearDown(): void
    {
        Context::clear();
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
        
        // 验证all()和copy()返回相同结果
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
        
        // 测试覆盖模式
        Context::merge([
            'key2' => 456,  // 应该覆盖
            'key3' => 'new' // 应该新增
        ]);
        
        $this->assertEquals('value1', Context::get('key1'));
        $this->assertEquals(456, Context::get('key2')); // 被覆盖
        $this->assertEquals('new', Context::get('key3')); // 新增
        $this->assertEquals(3, Context::count());
        
        // 测试非覆盖模式
        Context::merge([
            'key1' => 'new_value1', // 不应该覆盖
            'key4' => 'value4'      // 应该新增
        ], false);
        
        $this->assertEquals('value1', Context::get('key1')); // 未被覆盖
        $this->assertEquals(456, Context::get('key2'));
        $this->assertEquals('new', Context::get('key3'));
        $this->assertEquals('value4', Context::get('key4')); // 新增
        $this->assertEquals(4, Context::count());
    }

    /**
     * 测试run方法创建隔离作用域
     */
    public function testRun(): void
    {
        Context::set('outer', 'outer_value');
        
        $result = Context::run(function () {
            // 在新的上下文中设置值
            Context::set('inner', 'inner_value');
            
            // 验证内部值存在
            $this->assertTrue(Context::has('inner'));
            $this->assertEquals('inner_value', Context::get('inner'));
            
            // 验证外部值不存在（因为是新的上下文）
            $this->assertFalse(Context::has('outer'));
            
            return 'result';
        });
        
        // 验证run方法返回值
        $this->assertEquals('result', $result);
        
        // 验证回到外部上下文后，外部值存在
        $this->assertTrue(Context::has('outer'));
        $this->assertEquals('outer_value', Context::get('outer'));
        
        // 验证内部值不存在
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
}