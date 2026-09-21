<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use Kode\Context\ValueSerializer;
use PHPUnit\Framework\TestCase;

/**
 * v3.2.0 加固回归：clear() 代数守卫、序列化环/深度保护、内部键出入站隔离
 */
class HardeningV32Test extends TestCase
{
    protected function setUp(): void
    {
        Context::reset();
        ValueSerializer::reset();
    }

    protected function tearDown(): void
    {
        Context::reset();
        ValueSerializer::reset();
    }

    // ==================== clear() 与陈旧作用域句柄 ====================

    public function testClearNeutralisesLeakedScopeHandle(): void
    {
        Context::set('a', 1);
        $scope = Context::enter(['b' => 2]);

        Context::clear();
        self::assertNull(Context::get('a'));

        // 句柄被 GC 时不得把进入前的陈旧快照覆盖回来
        unset($scope);
        gc_collect_cycles();

        self::assertNull(Context::get('a'));
        self::assertNull(Context::get('b'));
    }

    public function testClearWipesStackFramesForLaterScopes(): void
    {
        Context::set('outer', 'x');
        $leaked = Context::enter(['mid' => 'y']);

        Context::clear();

        // clear 之后再进入新作用域并正常关闭，回滚目标是清空后的世界
        Context::run(static function (): void {
            Context::set('inner', 1);
            self::assertNull(Context::get('outer'));
        });

        self::assertNull(Context::get('inner'));
        self::assertNull(Context::get('outer'));

        unset($leaked);
        gc_collect_cycles();
        self::assertNull(Context::get('outer'), '陈旧句柄不得复活 clear 前的数据');
    }

    public function testClearInsideRunWithDoesNotLeakScopeData(): void
    {
        Context::set('caller', 'keep-me');

        Context::run(static function (): void {
            Context::clear();
            Context::set('in-scope', 'ghost');
        });

        self::assertNull(Context::get('in-scope'), 'clear() 后作用域残余数据不得带回调用方');
        self::assertNull(Context::get('caller'));
    }

    public function testEnterHandleClosedNormallyStillUnwinds(): void
    {
        Context::set('k', 1);
        $scope = Context::enter(['k' => 2]);
        self::assertSame(2, Context::get('k'));

        $scope->close();
        self::assertSame(1, Context::get('k'));
    }

    // ==================== ValueSerializer 环 / 深度 ====================

    public function testExportSurvivesObjectCycle(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->ref = $b;
        $b->ref = $a;

        Context::set('cyc', $a);

        $encoded = Context::export()['cyc'];
        self::assertSame('object', $encoded[ValueSerializer::TYPE_KEY]);
        self::assertSame('reference', $encoded['value']['ref']['value']['ref'][ValueSerializer::TYPE_KEY]);

        // 解码后环位置为 null，且 toJson 不再爆栈
        $decoded = Context::import(Context::export(), true);
        self::assertNull($decoded['cyc']['ref']['ref']);
        self::assertIsString(Context::toJson());
    }

    public function testDepthCapTruncatesDeepStructures(): void
    {
        $deep = 'leaf';

        for ($i = 0; $i < 64; $i++) {
            $deep = ['n' => $deep];
        }

        Context::set('deep', $deep);
        $encoded = Context::export()['deep'];

        $walk = $encoded;
        $sawTruncated = false;

        for ($i = 0; $i < 64; $i++) {
            if (!is_array($walk)) {
                break;
            }

            if (($walk[ValueSerializer::TYPE_KEY] ?? null) === 'truncated') {
                $sawTruncated = true;
                break;
            }

            $walk = $walk['n'] ?? null;
        }

        self::assertTrue($sawTruncated, '超过深度上限应编码为 truncated 标记');
    }

    public function testDecodeReferenceAndTruncatedBecomeNull(): void
    {
        self::assertNull(ValueSerializer::decode([ValueSerializer::TYPE_KEY => 'reference', 'class' => 'X']));
        self::assertNull(ValueSerializer::decode([ValueSerializer::TYPE_KEY => 'truncated']));
    }

    public function testDecodeDoesNotAutoloadUnknownClasses(): void
    {
        $triggered = false;
        spl_autoload_register(static function (string $class) use (&$triggered): void {
            if ($class === 'Totally\\Missing\\Enum12345') {
                $triggered = true;
            }
        });

        $out = ValueSerializer::decode([
            ValueSerializer::TYPE_KEY => 'enum',
            'class' => 'Totally\\Missing\\Enum12345',
            'value' => 'x',
        ]);

        self::assertSame('x', $out);
        self::assertFalse($triggered, '解码未知类名不得触发自动加载');
    }

    // ==================== 内部瞬态键出入站隔离 ====================

    public function testExportHidesInternalRuntimeKeys(): void
    {
        Context::set('__kode_http_request', ['headers' => ['Authorization' => 'Bearer top-secret']]);
        Context::set('user_id', 7);

        $json = Context::toJson();
        self::assertStringNotContainsString('top-secret', $json);
        self::assertStringContainsString('user_id', $json);

        // 显式列入 onlyKeys 也不放行
        self::assertSame([], Context::export(['__kode_http_request']));
    }

    public function testImportRejectsInternalKeys(): void
    {
        Context::import(['__kode_http_request' => 'evil', 'ok' => 1], true);

        self::assertNull(Context::get('__kode_http_request'));
        self::assertSame(1, Context::get('ok'));
    }

    public function testFromHeadersRejectsMalformedAndInternalKeys(): void
    {
        Context::fromHeaders([
            'X-Context-good_key' => 'v1',
            'X-Context-__kode_injected' => 'evil',
            'X-Context-9bad' => 'v2',
            'X-Context-' => 'v3',
            'X-Context-kebab-case' => 'v4',
        ]);

        self::assertSame('v1', Context::get('good_key'));
        self::assertNull(Context::get('__kode_injected'));
        self::assertNull(Context::get('9bad'));
        self::assertSame('v4', Context::get('kebab_case'));
    }

    public function testTransactionSnapshotStillCoversInternalKeys(): void
    {
        Context::set('__kode_http_request', 'live-request');

        Context::transaction(static function (): void {
            Context::set('__kode_http_request', 'temp');
        });

        self::assertSame('live-request', Context::get('__kode_http_request'));
    }
}
