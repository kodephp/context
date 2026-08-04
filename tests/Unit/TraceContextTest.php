<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use Kode\Context\TraceContext;
use PHPUnit\Framework\TestCase;

/**
 * W3C Trace Context / Baggage 测试
 */
class TraceContextTest extends TestCase
{
    protected function setUp(): void
    {
        Context::reset();
    }

    protected function tearDown(): void
    {
        Context::reset();
    }

    public function testGeneratedIdsMatchSpec(): void
    {
        $traceId = TraceContext::generateTraceId();
        $spanId = TraceContext::generateSpanId();

        $this->assertSame(32, strlen($traceId));
        $this->assertSame(16, strlen($spanId));
        $this->assertTrue(TraceContext::isValidTraceId($traceId));
        $this->assertTrue(TraceContext::isValidSpanId($spanId));
    }

    public function testInvalidIdsAreRejected(): void
    {
        $this->assertFalse(TraceContext::isValidTraceId(''));
        $this->assertFalse(TraceContext::isValidTraceId('zzzz'));
        $this->assertFalse(TraceContext::isValidTraceId(str_repeat('0', 32)), '全零 Trace ID 无效');
        $this->assertFalse(TraceContext::isValidSpanId(str_repeat('0', 16)), '全零 Span ID 无效');
        $this->assertFalse(TraceContext::isValidSpanId('abc'));
    }

    public function testBuildAndParseRoundTrip(): void
    {
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $spanId = '00f067aa0ba902b7';

        $header = TraceContext::build($traceId, $spanId, true);
        $this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $header);

        $parsed = TraceContext::parse((string)$header);
        $this->assertNotNull($parsed);
        $this->assertSame($traceId, $parsed['trace_id']);
        $this->assertSame($spanId, $parsed['span_id']);
        $this->assertTrue($parsed['sampled']);
    }

    public function testBuildRejectsInvalidIds(): void
    {
        $this->assertNull(TraceContext::build('bad-trace', '00f067aa0ba902b7'));
        $this->assertNull(TraceContext::build('4bf92f3577b34da6a3ce929d0e0e4736', 'bad-span'));
    }

    public function testUnsampledFlag(): void
    {
        $header = TraceContext::build('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', false);
        $this->assertStringEndsWith('-00', (string)$header);

        $parsed = TraceContext::parse((string)$header);
        $this->assertNotNull($parsed);
        $this->assertFalse($parsed['sampled']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTraceparentProvider(): array
    {
        return [
            '空字符串' => [''],
            '段数不足' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7'],
            '版本 ff 保留' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
            '全零 trace' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
            '全零 span' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
            '非十六进制' => ['00-zzf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
            '当前版本多余字段' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra'],
            '标志位非法' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-zz'],
        ];
    }

    /**
     * @dataProvider invalidTraceparentProvider
     */
    public function testParseRejectsInvalidHeaders(string $header): void
    {
        $this->assertNull(TraceContext::parse($header));
    }

    public function testFutureVersionWithExtraFieldsIsAccepted(): void
    {
        $parsed = TraceContext::parse('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-future');

        $this->assertNotNull($parsed);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $parsed['trace_id']);
    }

    public function testTracestateParseAndBuild(): void
    {
        $entries = TraceContext::parseTracestate('rojo=00f067aa0ba902b7, congo=t61rcWkgMzE');

        $this->assertSame(['rojo' => '00f067aa0ba902b7', 'congo' => 't61rcWkgMzE'], $entries);
        $this->assertSame('rojo=00f067aa0ba902b7,congo=t61rcWkgMzE', TraceContext::buildTracestate($entries));
    }

    public function testWithTracestateMovesVendorToFront(): void
    {
        $result = TraceContext::withTracestate('rojo=1,congo=2', 'congo', '9');

        $this->assertSame('congo=9,rojo=1', $result);
    }

    public function testBaggageRoundTripWithEncoding(): void
    {
        $built = TraceContext::buildBaggage(['user name' => '张三', 'tier' => 'vip']);
        $parsed = TraceContext::parseBaggage($built);

        $this->assertSame(['user name' => '张三', 'tier' => 'vip'], $parsed);
    }

    public function testBaggageIgnoresMetadata(): void
    {
        $parsed = TraceContext::parseBaggage('key1=value1;property=1,key2=value2');

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $parsed);
    }

    // ==================== Context 集成 ====================

    public function testStartTraceProducesW3CCompliantIds(): void
    {
        Context::startTrace();

        /** @var string $traceId */
        $traceId = Context::get(Context::TRACE_ID);
        /** @var string $spanId */
        $spanId = Context::get(Context::SPAN_ID);
        $this->assertTrue(TraceContext::isValidTraceId($traceId));
        $this->assertTrue(TraceContext::isValidSpanId($spanId));
        $this->assertTrue(Context::isSampled());
        $this->assertNotNull(Context::toTraceparent());
    }

    public function testToTraceparentReturnsNullForNonCompliantIds(): void
    {
        Context::startTrace('legacy-trace-id');

        $this->assertNull(Context::toTraceparent());
    }

    public function testFromTraceparentCreatesChildSpan(): void
    {
        $header = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        $this->assertTrue(Context::fromTraceparent($header, 'rojo=1'));
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', Context::get(Context::TRACE_ID));
        $this->assertSame('00f067aa0ba902b7', Context::get(Context::PARENT_SPAN_ID));
        $this->assertNotSame('00f067aa0ba902b7', Context::get(Context::SPAN_ID));
        /** @var string $spanId2 */
        $spanId2 = Context::get(Context::SPAN_ID);
        $this->assertTrue(TraceContext::isValidSpanId($spanId2));
        $this->assertSame('rojo=1', Context::get(Context::TRACE_STATE));
        $this->assertTrue(Context::isSampled());
    }

    public function testFromTraceparentReturnsFalseOnInvalid(): void
    {
        $this->assertFalse(Context::fromTraceparent('garbage'));
        $this->assertFalse(Context::has(Context::TRACE_ID));
    }

    public function testSampledToggle(): void
    {
        Context::startTrace();
        $this->assertTrue(Context::isSampled());

        Context::setSampled(false);
        $this->assertFalse(Context::isSampled());
        $this->assertStringEndsWith('-00', (string)Context::toTraceparent());

        Context::setSampled(true);
        $this->assertTrue(Context::isSampled());
    }

    public function testBaggageHelpers(): void
    {
        Context::setBaggage('tenant', 'acme');
        Context::setBaggage('region', 'cn-south');

        $this->assertSame('acme', Context::getBaggage('tenant'));
        $this->assertSame(['tenant' => 'acme', 'region' => 'cn-south'], Context::getBaggage());
        $this->assertNull(Context::getBaggage('missing'));
    }

    public function testW3CHeadersRoundTripAcrossNodes(): void
    {
        Context::startTrace();
        Context::setBaggage('tenant', 'acme');
        $upstreamTraceId = Context::get(Context::TRACE_ID);
        $upstreamSpanId = Context::get(Context::SPAN_ID);

        $headers = Context::toW3CHeaders();
        $this->assertArrayHasKey('traceparent', $headers);
        $this->assertArrayHasKey('baggage', $headers);

        Context::reset();

        $this->assertTrue(Context::fromW3CHeaders($headers));
        $this->assertSame($upstreamTraceId, Context::get(Context::TRACE_ID));
        $this->assertSame($upstreamSpanId, Context::get(Context::PARENT_SPAN_ID));
        $this->assertSame('acme', Context::getBaggage('tenant'));
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        Context::startTrace();
        $traceId = Context::get(Context::TRACE_ID);
        $headers = Context::toW3CHeaders();

        Context::reset();

        $upper = ['TRACEPARENT' => $headers['traceparent']];
        $this->assertTrue(Context::fromW3CHeaders($upper));
        $this->assertSame($traceId, Context::get(Context::TRACE_ID));
    }

    public function testFromW3CHeadersReturnsFalseWithoutTraceparent(): void
    {
        $this->assertFalse(Context::fromW3CHeaders(['baggage' => 'a=b']));
        $this->assertSame('b', Context::getBaggage('a'));
    }

    public function testToHeadersIncludesW3CByDefault(): void
    {
        Context::startTrace();

        $headers = Context::toHeaders();
        $this->assertArrayHasKey('X-Context-Trace-Id', $headers);
        $this->assertArrayHasKey('traceparent', $headers);

        $legacyOnly = Context::toHeaders('X-Context-', false);
        $this->assertArrayNotHasKey('traceparent', $legacyOnly);
    }

    public function testFromHeadersPrefersW3CWhenPresent(): void
    {
        Context::startTrace();
        Context::set(Context::NODE_ID, 'node-a');
        $traceId = Context::get(Context::TRACE_ID);
        $spanId = Context::get(Context::SPAN_ID);
        $headers = Context::toHeaders();

        Context::reset();
        Context::fromHeaders($headers);

        $this->assertSame($traceId, Context::get(Context::TRACE_ID));
        $this->assertSame('node-a', Context::get(Context::NODE_ID), '私有头字段仍然生效');
        $this->assertSame($spanId, Context::get(Context::PARENT_SPAN_ID), '上游 span 应下沉为 parent');
    }

    public function testFromLowercaseLegacyHeaders(): void
    {
        Context::fromHeaders([
            'x-context-trace-id' => 'trace-lower',
            'x-context-node-id' => 'node-lower',
        ]);

        $this->assertSame('trace-lower', Context::get(Context::TRACE_ID));
        $this->assertSame('node-lower', Context::get(Context::NODE_ID));
    }

    public function testContinueTraceStartsNewTraceWhenAbsent(): void
    {
        Context::continueTrace([]);

        $this->assertTrue(Context::has(Context::TRACE_ID));
        /** @var string $traceId3 */
        $traceId3 = Context::get(Context::TRACE_ID);
        $this->assertTrue(TraceContext::isValidTraceId($traceId3));
    }

    public function testContinueTraceKeepsUpstreamTrace(): void
    {
        Context::startTrace();
        $traceId = Context::get(Context::TRACE_ID);
        $headers = Context::toHeaders();

        Context::reset();
        Context::continueTrace($headers);

        $this->assertSame($traceId, Context::get(Context::TRACE_ID));
    }
}
