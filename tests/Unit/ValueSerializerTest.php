<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use DateTimeImmutable;
use Kode\Context\Context;
use Kode\Context\ContextException;
use Kode\Context\ValueSerializer;
use PHPUnit\Framework\TestCase;

/**
 * 序列化器测试（含反序列化安全约束）
 */
class ValueSerializerTest extends TestCase
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

    public function testScalarsPassThrough(): void
    {
        foreach ([null, 1, 1.5, true, false, 'text'] as $value) {
            $this->assertSame($value, ValueSerializer::decode(ValueSerializer::encode($value)));
        }
    }

    public function testNestedArraysKeepKeys(): void
    {
        $value = ['a' => 1, 'b' => ['c' => [1, 2, 3], 'd' => 'x']];

        $this->assertSame($value, ValueSerializer::decode(ValueSerializer::encode($value)));
    }

    public function testDateTimeRoundTrip(): void
    {
        $date = new DateTimeImmutable('2024-01-15T10:30:00+00:00');

        $decoded = ValueSerializer::decode(ValueSerializer::encode($date));

        $this->assertInstanceOf(DateTimeImmutable::class, $decoded);
        $this->assertSame($date->format(DATE_ATOM), $decoded->format(DATE_ATOM));
    }

    public function testBackedEnumRoundTrip(): void
    {
        $decoded = ValueSerializer::decode(ValueSerializer::encode(SerializerTestStatus::Active));

        $this->assertSame(SerializerTestStatus::Active, $decoded);
    }

    public function testPureEnumRoundTrip(): void
    {
        $decoded = ValueSerializer::decode(ValueSerializer::encode(SerializerTestColor::Red));

        $this->assertSame(SerializerTestColor::Red, $decoded);
    }

    public function testUnknownEnumFallsBackToRawValue(): void
    {
        $decoded = ValueSerializer::decode([
            ValueSerializer::TYPE_KEY => 'enum',
            'class' => 'App\\Missing\\Enum',
            'value' => 'active',
        ]);

        $this->assertSame('active', $decoded);
    }

    public function testPlainObjectIsNotHydratedByDefault(): void
    {
        $user = new SerializerTestUser(9, 'kode');

        $encoded = ValueSerializer::encode($user);
        $this->assertSame('object', $encoded[ValueSerializer::TYPE_KEY]);

        $decoded = ValueSerializer::decode($encoded);

        $this->assertIsArray($decoded, '未列入白名单的类不得被还原为对象，防止对象注入');
        $this->assertSame(['id' => 9, 'name' => 'kode'], $decoded);
    }

    public function testAllowedObjectIsHydrated(): void
    {
        ValueSerializer::allow(SerializerTestUser::class);

        $decoded = ValueSerializer::decode(ValueSerializer::encode(new SerializerTestUser(9, 'kode')));

        $this->assertInstanceOf(SerializerTestUser::class, $decoded);
        $this->assertSame(9, $decoded->id);
        $this->assertSame('kode', $decoded->name);
    }

    public function testCustomCodec(): void
    {
        ValueSerializer::register(
            SerializerTestMoney::class,
            static fn (SerializerTestMoney $m): array => ['amount' => $m->amount, 'currency' => $m->currency],
            static fn (array $raw): SerializerTestMoney => new SerializerTestMoney(
                (int)$raw['amount'],
                (string)$raw['currency']
            ),
        );

        $decoded = ValueSerializer::decode(ValueSerializer::encode(new SerializerTestMoney(1990, 'CNY')));

        $this->assertInstanceOf(SerializerTestMoney::class, $decoded);
        $this->assertSame(1990, $decoded->amount);
        $this->assertSame('CNY', $decoded->currency);
    }

    public function testClosureAndResourceDegradeSafely(): void
    {
        $this->assertNull(ValueSerializer::decode(ValueSerializer::encode(static fn (): int => 1)));

        $handle = fopen('php://memory', 'rb');
        $this->assertIsResource($handle);
        $encoded = ValueSerializer::encode($handle);
        fclose($handle);

        $this->assertSame('resource', $encoded[ValueSerializer::TYPE_KEY]);
        $this->assertNull(ValueSerializer::decode($encoded));
    }

    public function testIsAllowed(): void
    {
        $this->assertFalse(ValueSerializer::isAllowed(SerializerTestUser::class));

        ValueSerializer::allow(SerializerTestUser::class);
        $this->assertTrue(ValueSerializer::isAllowed(SerializerTestUser::class));

        ValueSerializer::reset();
        $this->assertFalse(ValueSerializer::isAllowed(SerializerTestUser::class));
    }

    // ==================== Context 集成 ====================

    public function testContextJsonRoundTripWithEnumAndDate(): void
    {
        Context::set('status', SerializerTestStatus::Active);
        Context::set('created_at', new DateTimeImmutable('2024-06-01T08:00:00+00:00'));
        Context::set('tags', ['a', 'b']);

        $json = Context::toJson();

        Context::reset();
        Context::fromJson($json);

        $this->assertSame(SerializerTestStatus::Active, Context::get('status'));
        $this->assertInstanceOf(DateTimeImmutable::class, Context::get('created_at'));
        $this->assertSame(['a', 'b'], Context::get('tags'));
    }

    public function testFromJsonRejectsMalformedPayload(): void
    {
        $this->expectException(ContextException::class);
        Context::fromJson('{"a":');
    }

    public function testFromJsonRejectsScalarPayload(): void
    {
        $this->expectException(ContextException::class);
        Context::fromJson('123');
    }

    public function testMaliciousPayloadDoesNotInstantiateObjects(): void
    {
        $payload = json_encode([
            'evil' => [
                ValueSerializer::TYPE_KEY => 'object',
                'class' => SerializerTestUser::class,
                'value' => ['id' => 1, 'name' => 'attacker'],
            ],
        ]);

        $this->assertIsString($payload);
        Context::fromJson($payload);

        $this->assertIsArray(Context::get('evil'));
    }

    public function testUnicodeIsNotEscaped(): void
    {
        Context::set('name', '张三');

        $this->assertStringContainsString('张三', Context::toJson());
    }
}

enum SerializerTestStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}

enum SerializerTestColor
{
    case Red;
    case Blue;
}

final class SerializerTestUser
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}

final class SerializerTestMoney
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
    }
}
