<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use Kode\Context\ContextException;
use PHPUnit\Framework\TestCase;

/**
 * Context 分布式功能测试
 */
class DistributedContextTest extends TestCase
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
     * 测试 JSON 序列化
     */
    public function testToJson(): void
    {
        Context::set('user_id', 123);
        Context::set('name', 'test');
        Context::set('active', true);

        $json = Context::toJson();
        $this->assertJson($json);

        $data = json_decode($json, true);
        $this->assertEquals(123, $data['user_id']);
        $this->assertEquals('test', $data['name']);
        $this->assertTrue($data['active']);
    }

    /**
     * 测试 JSON 序列化指定键
     */
    public function testToJsonWithOnlyKeys(): void
    {
        Context::set('user_id', 123);
        Context::set('name', 'test');
        Context::set('secret', 'hidden');

        $json = Context::toJson(['user_id', 'name']);
        $data = json_decode($json, true);
        $this->assertIsArray($data);

        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayNotHasKey('secret', $data);
    }

    /**
     * 测试 JSON 反序列化
     */
    public function testFromJson(): void
    {
        $json = '{"user_id":123,"name":"test","active":true}';
        $data = Context::fromJson($json);

        $this->assertEquals(123, Context::get('user_id'));
        $this->assertEquals('test', Context::get('name'));
        $this->assertTrue(Context::get('active'));
        $this->assertEquals(123, $data['user_id']);
    }

    /**
     * 测试 JSON 反序列化合并模式
     */
    public function testFromJsonMerge(): void
    {
        Context::set('existing_key', 'existing_value');
        Context::set('user_id', 999);

        $json = '{"user_id":123,"name":"test"}';
        Context::fromJson($json, true);

        $this->assertEquals(123, Context::get('user_id'));
        $this->assertEquals('test', Context::get('name'));
        $this->assertEquals('existing_value', Context::get('existing_key'));
    }

    /**
     * 测试无效 JSON 反序列化
     */
    public function testFromJsonInvalid(): void
    {
        $this->expectException(ContextException::class);
        Context::fromJson('invalid json');
    }

    /**
     * 测试导出功能
     */
    public function testExport(): void
    {
        Context::set('user_id', 123);
        Context::set('name', 'test');

        $exported = Context::export();
        $this->assertIsArray($exported);
        $this->assertEquals(123, $exported['user_id']);
        $this->assertEquals('test', $exported['name']);
    }

    /**
     * 测试导入功能
     */
    public function testImport(): void
    {
        $data = [
            'user_id' => 123,
            'name' => 'test',
        ];

        Context::import($data);

        $this->assertEquals(123, Context::get('user_id'));
        $this->assertEquals('test', Context::get('name'));
    }

    /**
     * 测试分布式追踪
     */
    public function testStartTrace(): void
    {
        $traceId = Context::startTrace();

        $this->assertNotEmpty($traceId);
        $this->assertEquals(32, strlen($traceId));
        $this->assertTrue(Context::has(Context::TRACE_ID));
        $this->assertTrue(Context::has(Context::SPAN_ID));
        $this->assertEquals($traceId, Context::get(Context::TRACE_ID));
    }

    /**
     * 测试指定 Trace ID
     */
    public function testStartTraceWithCustomId(): void
    {
        $customTraceId = 'custom-trace-id-12345';
        $traceId = Context::startTrace($customTraceId, 'node-1');

        $this->assertEquals($customTraceId, $traceId);
        $this->assertEquals('node-1', Context::get(Context::NODE_ID));
    }

    /**
     * 测试创建子 Span
     */
    public function testStartSpan(): void
    {
        Context::startTrace();
        $parentSpanId = Context::get(Context::SPAN_ID);

        $newSpanId = Context::startSpan();

        $this->assertNotEmpty($newSpanId);
        $this->assertNotEquals($parentSpanId, $newSpanId);
        $this->assertEquals($parentSpanId, Context::get(Context::PARENT_SPAN_ID));
        $this->assertEquals($newSpanId, Context::get(Context::SPAN_ID));
    }

    /**
     * 测试获取追踪信息
     */
    public function testGetTraceInfo(): void
    {
        Context::startTrace('trace-123', 'node-1');
        Context::startSpan();

        $traceInfo = Context::getTraceInfo();

        $this->assertEquals('trace-123', $traceInfo[Context::TRACE_ID]);
        $this->assertNotEmpty($traceInfo[Context::SPAN_ID]);
        $this->assertNotEmpty($traceInfo[Context::PARENT_SPAN_ID]);
        $this->assertEquals('node-1', $traceInfo[Context::NODE_ID]);
    }

    /**
     * 测试设置来源节点
     */
    public function testSetSourceNode(): void
    {
        Context::setSourceNode('source-node-1');
        $this->assertEquals('source-node-1', Context::get(Context::SOURCE_NODE_ID));
    }

    /**
     * 测试设置关联 ID
     */
    public function testSetCorrelationId(): void
    {
        Context::setCorrelationId('corr-123');
        $this->assertEquals('corr-123', Context::get(Context::CORRELATION_ID));
    }

    /**
     * 测试设置请求 ID
     */
    public function testSetRequestId(): void
    {
        Context::setRequestId('req-456');
        $this->assertEquals('req-456', Context::get(Context::REQUEST_ID));
    }

    /**
     * 测试获取分布式键
     */
    public function testGetDistributedKeys(): void
    {
        $keys = Context::getDistributedKeys();

        $this->assertIsArray($keys);
        $this->assertContains(Context::TRACE_ID, $keys);
        $this->assertContains(Context::SPAN_ID, $keys);
        $this->assertContains(Context::NODE_ID, $keys);
    }

    /**
     * 测试导出分布式上下文
     */
    public function testExportForDistributed(): void
    {
        Context::startTrace('trace-123', 'node-1');
        Context::set('user_id', 123);

        $exported = Context::exportForDistributed();

        $this->assertArrayHasKey(Context::TRACE_ID, $exported);
        $this->assertArrayHasKey(Context::SPAN_ID, $exported);
        $this->assertArrayHasKey(Context::NODE_ID, $exported);
        $this->assertArrayNotHasKey('user_id', $exported);
    }

    /**
     * 测试导出为 HTTP Headers
     */
    public function testToHeaders(): void
    {
        Context::startTrace('trace-123', 'node-1');

        $headers = Context::toHeaders();

        $this->assertArrayHasKey('X-Context-Trace-Id', $headers);
        $this->assertArrayHasKey('X-Context-Span-Id', $headers);
        $this->assertArrayHasKey('X-Context-Node-Id', $headers);
        $this->assertEquals('trace-123', $headers['X-Context-Trace-Id']);
        $this->assertEquals('node-1', $headers['X-Context-Node-Id']);
    }

    /**
     * 测试自定义 Header 前缀
     */
    public function testToHeadersWithCustomPrefix(): void
    {
        Context::startTrace('trace-123');

        $headers = Context::toHeaders('X-Trace-');

        $this->assertArrayHasKey('X-Trace-Trace-Id', $headers);
        $this->assertArrayHasKey('X-Trace-Span-Id', $headers);
    }

    /**
     * 测试从 HTTP Headers 导入
     */
    public function testFromHeaders(): void
    {
        $headers = [
            'X-Context-Trace-Id' => 'trace-456',
            'X-Context-Span-Id' => 'span-789',
            'X-Context-Node-Id' => 'node-2',
        ];

        Context::fromHeaders($headers);

        $this->assertEquals('trace-456', Context::get(Context::TRACE_ID));
        $this->assertEquals('span-789', Context::get(Context::SPAN_ID));
        $this->assertEquals('node-2', Context::get(Context::NODE_ID));
    }

    /**
     * 测试 DateTime 序列化
     */
    public function testDateTimeSerialization(): void
    {
        $date = new \DateTimeImmutable('2024-01-15 10:30:00');
        Context::set('created_at', $date);

        $json = Context::toJson();
        $data = json_decode($json, true);

        $this->assertIsArray($data['created_at']);
        $this->assertEquals('datetime', $data['created_at']['__type__']);
        $this->assertEquals('2024-01-15T10:30:00+00:00', $data['created_at']['value']);
    }

    /**
     * 测试 DateTime 反序列化
     */
    public function testDateTimeUnserialization(): void
    {
        $json = '{"created_at":{"__type__":"datetime","value":"2024-01-15T10:30:00+00:00"}}';
        Context::fromJson($json);

        $date = Context::get('created_at');
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertEquals('2024-01-15T10:30:00+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    /**
     * 测试完整分布式流程
     */
    public function testFullDistributedFlow(): void
    {
        Context::startTrace(null, 'node-source');
        Context::setRequestId('req-123');
        Context::set('user_id', 456);

        $headers = Context::toHeaders();

        Context::reset();

        Context::fromHeaders($headers);

        $this->assertTrue(Context::has(Context::TRACE_ID));
        $this->assertTrue(Context::has(Context::SPAN_ID));
        $this->assertEquals('node-source', Context::get(Context::NODE_ID));
        $this->assertFalse(Context::has('user_id'));
    }

    /**
     * 测试跨节点上下文传递
     */
    public function testCrossNodeContextTransfer(): void
    {
        Context::startTrace('trace-cross-node', 'node-a');
        Context::set('user_id', 789);

        $json = Context::toJson(Context::getDistributedKeys());

        Context::reset();

        Context::fromJson($json);

        $this->assertEquals('trace-cross-node', Context::get(Context::TRACE_ID));
        $this->assertEquals('node-a', Context::get(Context::NODE_ID));
        $this->assertFalse(Context::has('user_id'));
    }

    /**
     * 测试 JsonSerializable 接口
     */
    public function testJsonSerializable(): void
    {
        Context::set('key', 'value');
        $data = Context::export();

        $this->assertIsArray($data);
        $this->assertEquals('value', $data['key']);
        
        $json = json_encode($data);
        $this->assertIsString($json);
        $this->assertJson($json);
    }
}
