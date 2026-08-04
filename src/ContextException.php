<?php

declare(strict_types=1);

namespace Kode\Context;

use RuntimeException;
use Throwable;

/**
 * 上下文异常
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
class ContextException extends RuntimeException
{
    /**
     * @param string         $message  异常消息
     * @param int            $code     异常码
     * @param Throwable|null $previous 上一个异常
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 键不存在
     */
    public static function keyNotFound(string $key): self
    {
        return new self("上下文键 '{$key}' 不存在");
    }

    /**
     * 类型不匹配
     */
    public static function typeMismatch(string $key, string $expected, mixed $actual): self
    {
        return new self(
            "上下文键 '{$key}' 的值不是 {$expected} 类型，实际类型为 " . get_debug_type($actual)
        );
    }

    /**
     * 缺少扩展
     */
    public static function missingExtension(string $extension, string $feature): self
    {
        return new self("{$extension} 扩展未安装，无法使用{$feature}");
    }

    /**
     * 序列化失败
     */
    public static function serializeFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self('上下文序列化失败: ' . $reason, 0, $previous);
    }

    /**
     * 反序列化失败
     */
    public static function unserializeFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self('上下文反序列化失败: ' . $reason, 0, $previous);
    }
}
