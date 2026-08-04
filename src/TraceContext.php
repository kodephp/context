<?php

declare(strict_types=1);

namespace Kode\Context;

/**
 * W3C Trace Context / Baggage 规范实现
 *
 * 参考规范：
 * - https://www.w3.org/TR/trace-context/
 * - https://www.w3.org/TR/baggage/
 *
 * 通过该类生成的 traceparent 头可以与 OpenTelemetry、Jaeger、Zipkin、
 * SkyWalking 等主流 APM 系统直接互通，无需自定义协议。
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
final class TraceContext
{
    /** 当前支持的 traceparent 版本 */
    public const string VERSION = '00';

    /** 采样标志位 */
    public const int FLAG_SAMPLED = 0x01;

    /** 全零 Trace ID（无效值） */
    private const string INVALID_TRACE_ID = '00000000000000000000000000000000';

    /** 全零 Span ID（无效值） */
    private const string INVALID_SPAN_ID = '0000000000000000';

    /** tracestate 最大条目数（规范限制） */
    public const int MAX_TRACESTATE_ENTRIES = 32;

    private function __construct()
    {
    }

    /**
     * 生成符合 W3C 规范的 Trace ID（16 字节 / 32 位十六进制）
     */
    public static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 生成符合 W3C 规范的 Span ID（8 字节 / 16 位十六进制）
     */
    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * 校验 Trace ID 是否符合 W3C 规范
     */
    public static function isValidTraceId(string $traceId): bool
    {
        return strlen($traceId) === 32
            && ctype_xdigit($traceId)
            && strtolower($traceId) !== self::INVALID_TRACE_ID;
    }

    /**
     * 校验 Span ID 是否符合 W3C 规范
     */
    public static function isValidSpanId(string $spanId): bool
    {
        return strlen($spanId) === 16
            && ctype_xdigit($spanId)
            && strtolower($spanId) !== self::INVALID_SPAN_ID;
    }

    /**
     * 构造 traceparent 头
     *
     * @param string $traceId 32 位十六进制 Trace ID
     * @param string $spanId  16 位十六进制 Span ID
     * @param bool   $sampled 是否采样
     * @return string|null ID 不符合规范时返回 null
     */
    public static function build(string $traceId, string $spanId, bool $sampled = true): ?string
    {
        if (!self::isValidTraceId($traceId) || !self::isValidSpanId($spanId)) {
            return null;
        }

        $flags = sprintf('%02x', $sampled ? self::FLAG_SAMPLED : 0);

        return self::VERSION . '-' . strtolower($traceId) . '-' . strtolower($spanId) . '-' . $flags;
    }

    /**
     * 解析 traceparent 头
     *
     * @param string $traceparent 原始头值
     * @return array{trace_id: string, span_id: string, flags: int, sampled: bool}|null 无效时返回 null
     */
    public static function parse(string $traceparent): ?array
    {
        $traceparent = trim($traceparent);
        $parts = explode('-', $traceparent);

        if (count($parts) < 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        // 版本号必须是 2 位十六进制，且 ff 为保留的非法值
        if (strlen($version) !== 2 || !ctype_xdigit($version) || strtolower($version) === 'ff') {
            return null;
        }

        // 未来版本允许携带额外字段，当前版本则必须严格等于 4 段
        if (strtolower($version) === self::VERSION && count($parts) !== 4) {
            return null;
        }

        if (!self::isValidTraceId($traceId) || !self::isValidSpanId($spanId)) {
            return null;
        }

        if (strlen($flags) !== 2 || !ctype_xdigit($flags)) {
            return null;
        }

        $flagValue = (int)hexdec($flags);

        return [
            'trace_id' => strtolower($traceId),
            'span_id' => strtolower($spanId),
            'flags' => $flagValue,
            'sampled' => ($flagValue & self::FLAG_SAMPLED) === self::FLAG_SAMPLED,
        ];
    }

    /**
     * 解析 tracestate 头
     *
     * @param string $tracestate 原始头值
     * @return array<string, string> 有序的 key => value 映射
     */
    public static function parseTracestate(string $tracestate): array
    {
        $entries = [];

        foreach (explode(',', $tracestate) as $item) {
            $item = trim($item);
            if ($item === '' || !str_contains($item, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $item, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '' || isset($entries[$key])) {
                continue;
            }

            $entries[$key] = $value;

            if (count($entries) >= self::MAX_TRACESTATE_ENTRIES) {
                break;
            }
        }

        return $entries;
    }

    /**
     * 构造 tracestate 头
     *
     * @param array<string, string> $entries key => value 映射
     */
    public static function buildTracestate(array $entries): string
    {
        $parts = [];

        foreach ($entries as $key => $value) {
            if ($key === '') {
                continue;
            }

            $parts[] = $key . '=' . $value;

            if (count($parts) >= self::MAX_TRACESTATE_ENTRIES) {
                break;
            }
        }

        return implode(',', $parts);
    }

    /**
     * 在 tracestate 头首部插入或提升一个厂商条目
     *
     * @param string $tracestate 原始头值
     * @param string $vendor     厂商标识
     * @param string $value      值
     */
    public static function withTracestate(string $tracestate, string $vendor, string $value): string
    {
        $entries = self::parseTracestate($tracestate);
        unset($entries[$vendor]);

        return self::buildTracestate([$vendor => $value] + $entries);
    }

    /**
     * 解析 baggage 头
     *
     * @param string $baggage 原始头值
     * @return array<string, string> key => value 映射（值已 URL 解码）
     */
    public static function parseBaggage(string $baggage): array
    {
        $entries = [];

        foreach (explode(',', $baggage) as $item) {
            // 去掉 ;metadata 部分
            $item = trim(explode(';', $item, 2)[0]);

            if ($item === '' || !str_contains($item, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $item, 2);
            $key = trim($key);

            if ($key === '') {
                continue;
            }

            $entries[rawurldecode($key)] = rawurldecode(trim($value));
        }

        return $entries;
    }

    /**
     * 构造 baggage 头
     *
     * @param array<string, string> $entries key => value 映射
     */
    public static function buildBaggage(array $entries): string
    {
        $parts = [];

        foreach ($entries as $key => $value) {
            if ($key === '') {
                continue;
            }

            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode(',', $parts);
    }
}
