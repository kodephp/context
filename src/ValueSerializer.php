<?php

declare(strict_types=1);

namespace Kode\Context;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use JsonSerializable;
use Throwable;
use UnitEnum;

/**
 * 上下文值序列化器
 *
 * 负责把上下文中的任意 PHP 值转换为可跨进程 / 跨节点传输的纯数组结构，并在对端还原。
 *
 * 安全约定（重要）：
 * 反序列化默认**不会**把普通对象还原成实例，只返回其数组表示。
 * 这是为了阻断经由 HTTP 头或消息队列传入的恶意载荷触发对象注入攻击。
 * 如需还原为真实对象，必须显式登记白名单：
 *
 * ```php
 * ValueSerializer::allow(App\Dto\CurrentUser::class);
 * // 或注册完全自定义的编解码器
 * ValueSerializer::register(Money::class,
 *     fn (Money $m) => ['amount' => $m->amount, 'currency' => $m->currency],
 *     fn (array $raw) => new Money($raw['amount'], $raw['currency']),
 * );
 * ```
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
final class ValueSerializer
{
    /** 类型标记字段名 */
    public const string TYPE_KEY = '__type__';

    /**
     * 自定义编解码器
     *
     * @var array<class-string, array{encode: Closure, decode: Closure}>
     */
    private static array $codecs = [];

    /**
     * 允许在反序列化阶段还原为真实对象的类白名单
     *
     * @var array<class-string, true>
     */
    private static array $allowed = [];

    private function __construct()
    {
    }

    /**
     * 注册自定义编解码器
     *
     * @param class-string $class  目标类
     * @param Closure      $encode 编码函数，接收对象返回可 JSON 化的数据
     * @param Closure      $decode 解码函数，接收数据返回对象
     */
    public static function register(string $class, Closure $encode, Closure $decode): void
    {
        self::$codecs[$class] = ['encode' => $encode, 'decode' => $decode];
        self::$allowed[$class] = true;
    }

    /**
     * 把类加入反序列化白名单（使用 __unserialize 或公开属性还原）
     *
     * @param class-string ...$classes
     */
    public static function allow(string ...$classes): void
    {
        foreach ($classes as $class) {
            self::$allowed[$class] = true;
        }
    }

    /**
     * 判断类是否已被允许还原
     *
     * @param class-string|string $class
     */
    public static function isAllowed(string $class): bool
    {
        return isset(self::$allowed[$class]);
    }

    /**
     * 清空所有自定义编解码器与白名单
     */
    public static function reset(): void
    {
        self::$codecs = [];
        self::$allowed = [];
    }

    /**
     * 编码单个值
     */
    public static function encode(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(self::encode(...), $value);
        }

        if (is_resource($value)) {
            return [
                self::TYPE_KEY => 'resource',
                'type' => get_resource_type($value),
            ];
        }

        if (!is_object($value)) {
            return $value;
        }

        $class = $value::class;

        if (isset(self::$codecs[$class])) {
            return [
                self::TYPE_KEY => 'custom',
                'class' => $class,
                'value' => self::encode((self::$codecs[$class]['encode'])($value)),
            ];
        }

        if ($value instanceof DateTimeInterface) {
            return [
                self::TYPE_KEY => 'datetime',
                'class' => $class,
                'value' => $value->format(DateTimeInterface::ATOM),
            ];
        }

        if ($value instanceof BackedEnum) {
            return [
                self::TYPE_KEY => 'enum',
                'class' => $class,
                'value' => $value->value,
            ];
        }

        if ($value instanceof UnitEnum) {
            return [
                self::TYPE_KEY => 'unit_enum',
                'class' => $class,
                'name' => $value->name,
            ];
        }

        if ($value instanceof Closure) {
            return [
                self::TYPE_KEY => 'closure',
                'class' => $class,
            ];
        }

        if ($value instanceof JsonSerializable) {
            return [
                self::TYPE_KEY => 'json_serializable',
                'class' => $class,
                'value' => self::encode($value->jsonSerialize()),
            ];
        }

        if (method_exists($value, '__serialize')) {
            /** @var array<string, mixed> $state */
            $state = $value->__serialize();

            return [
                self::TYPE_KEY => 'serializable',
                'class' => $class,
                'value' => self::encode($state),
            ];
        }

        return [
            self::TYPE_KEY => 'object',
            'class' => $class,
            'value' => self::encode(get_object_vars($value)),
        ];
    }

    /**
     * 解码单个值
     */
    public static function decode(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!isset($value[self::TYPE_KEY]) || !is_string($value[self::TYPE_KEY])) {
            return array_map(self::decode(...), $value);
        }

        /** @var array<string, mixed> $value */
        $type = $value[self::TYPE_KEY];
        $class = isset($value['class']) && is_string($value['class']) ? $value['class'] : null;
        $payload = array_key_exists('value', $value) ? self::decode($value['value']) : null;

        return match ($type) {
            'datetime' => self::decodeDateTime($payload),
            'enum' => self::decodeEnum($class, $payload),
            'unit_enum' => self::decodeUnitEnum($class, $value['name'] ?? null),
            'custom' => self::decodeCustom($class, $payload),
            'serializable', 'object' => self::decodeObject($class, $payload),
            'json_serializable' => $payload,
            'closure', 'resource' => null,
            default => $payload ?? $value,
        };
    }

    /**
     * 还原时间对象
     */
    private static function decodeDateTime(mixed $payload): mixed
    {
        if (!is_string($payload)) {
            return $payload;
        }

        try {
            return new DateTimeImmutable($payload);
        } catch (Exception) {
            return $payload;
        }
    }

    /**
     * 还原带值枚举
     */
    private static function decodeEnum(?string $class, mixed $payload): mixed
    {
        if ($class === null || !enum_exists($class) || !is_subclass_of($class, BackedEnum::class)) {
            return $payload;
        }

        if (!is_int($payload) && !is_string($payload)) {
            return $payload;
        }

        return $class::tryFrom($payload) ?? $payload;
    }

    /**
     * 还原纯枚举
     */
    private static function decodeUnitEnum(?string $class, mixed $name): mixed
    {
        if ($class === null || !is_string($name) || !enum_exists($class)) {
            return $name;
        }

        foreach ($class::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return $name;
    }

    /**
     * 使用自定义编解码器还原
     */
    private static function decodeCustom(?string $class, mixed $payload): mixed
    {
        if ($class === null || !isset(self::$codecs[$class])) {
            return $payload;
        }

        try {
            return (self::$codecs[$class]['decode'])($payload);
        } catch (Throwable) {
            return $payload;
        }
    }

    /**
     * 还原普通对象（仅限白名单，默认返回数组表示）
     */
    private static function decodeObject(?string $class, mixed $payload): mixed
    {
        if ($class === null || !self::isAllowed($class) || !class_exists($class)) {
            return $payload;
        }

        if (!is_array($payload)) {
            return $payload;
        }

        try {
            $reflection = new \ReflectionClass($class);
            $object = $reflection->newInstanceWithoutConstructor();

            if (method_exists($object, '__unserialize')) {
                $object->__unserialize($payload);

                return $object;
            }

            foreach ($payload as $property => $propertyValue) {
                if (!is_string($property) || !$reflection->hasProperty($property)) {
                    continue;
                }

                $reflection->getProperty($property)->setValue($object, $propertyValue);
            }

            return $object;
        } catch (Throwable) {
            return $payload;
        }
    }
}
