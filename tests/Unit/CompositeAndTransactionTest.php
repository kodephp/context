<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use PHPUnit\Framework\TestCase;

/**
 * 组合键检查（hasAll / hasAny）与事务作用域（transaction）测试
 */
class CompositeAndTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        Context::reset();
    }

    protected function tearDown(): void
    {
        Context::reset();
    }

    public function testHasAllReturnsTrueOnlyWhenEveryKeyExists(): void
    {
        Context::set('a', 1);
        Context::set('b', 2);

        $this->assertTrue(Context::hasAll(['a', 'b']));
        $this->assertFalse(Context::hasAll(['a', 'b', 'c']));
    }

    public function testHasAllEmptyArrayIsTrue(): void
    {
        $this->assertTrue(Context::hasAll([]));
    }

    public function testHasAnyReturnsTrueWhenAtLeastOneKeyExists(): void
    {
        Context::set('a', 1);

        $this->assertTrue(Context::hasAny(['a', 'missing']));
        $this->assertFalse(Context::hasAny(['x', 'y']));
    }

    public function testHasAnyEmptyArrayIsFalse(): void
    {
        $this->assertFalse(Context::hasAny([]));
    }

    public function testTransactionRestoresSnapshotAfterSuccess(): void
    {
        Context::set('before', 'kept');

        $result = Context::transaction(function () {
            Context::set('temp', 'transient');
            Context::set('before', 'changed-inside');
            return 'ok';
        });

        $this->assertSame('ok', $result);
        // 临时键被回滚
        $this->assertFalse(Context::has('temp'));
        // 外部键恢复原值
        $this->assertSame('kept', Context::get('before'));
    }

    public function testTransactionRestoresSnapshotAfterException(): void
    {
        Context::set('before', 'kept');

        $thrown = null;
        try {
            Context::transaction(function () {
                Context::set('temp', 'transient');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertSame('boom', $thrown->getMessage());
        $this->assertFalse(Context::has('temp'));
        $this->assertSame('kept', Context::get('before'));
    }
}
