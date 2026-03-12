<?php

declare(strict_types=1);

namespace Kode\Context;

use RuntimeException;

/**
 * 上下文异常类
 *
 * 当上下文操作出现问题时抛出此异常
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
final class ContextException extends RuntimeException
{
    /**
     * 创建键不存在异常
     *
     * @param string $key 键名
     * @return self
     */
    public static function keyNotFound(string $key): self
    {
        return new self("上下文键 '{$key}' 不存在");
    }

    /**
     * 创建类型不匹配异常
     *
     * @param string $key          键名
     * @param string $expectedType 期望类型
     * @param string $actualType   实际类型
     * @return self
     */
    public static function typeMismatch(string $key, string $expectedType, string $actualType): self
    {
        return new self(
            "上下文键 '{$key}' 的值不是 {$expectedType} 类型，实际类型为 {$actualType}"
        );
    }

    /**
     * 创建无效操作异常
     *
     * @param string $message 错误消息
     * @return self
     */
    public static function invalidOperation(string $message): self
    {
        return new self($message);
    }
}
