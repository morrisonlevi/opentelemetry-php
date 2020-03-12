<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Integration;

use OpenTelemetry\Sdk\Trace\Attribute;
use OpenTelemetry\Sdk\Trace\Attributes;
use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Sdk\Trace\SpanStatus;
use OpenTelemetry\Sdk\Trace\Tracer;
use OpenTelemetry\Sdk\Trace\ZipkinExporter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TracingTest extends TestCase
{
    public function testContextGenerationAndRestore()
    {
        $spanContext = SpanContext::generate();
        $this->assertSame(strlen($spanContext->getTraceId()), 32);
        $this->assertSame(strlen($spanContext->getSpanId()), 16);

        $spanContext2 = SpanContext::generate();
        $this->assertNotSame($spanContext->getTraceId(), $spanContext2->getTraceId());
        $this->assertNotSame($spanContext->getSpanId(), $spanContext2->getSpanId());

        $spanContext3 = SpanContext::restore($spanContext->getTraceId(), $spanContext->getSpanId());
        $this->assertSame($spanContext3->getTraceId(), $spanContext->getTraceId());
        $this->assertSame($spanContext3->getSpanId(), $spanContext->getSpanId());
    }

    public function testTracerSpanContextRestore()
    {
        $tracer = new Tracer();
        $spanContext = $tracer->getActiveSpan()->getContext();

        $spanContext2 = SpanContext::restore($spanContext->getTraceId(), $spanContext->getSpanId());
        $tracer2 = new Tracer([], $spanContext2);

        $this->assertSame($tracer->getActiveSpan()->getContext()->getTraceId(), $tracer2->getActiveSpan()->getContext()->getTraceId());
    }

    public function testSpanNameUpdate()
    {
        $database = (new Tracer())->createSpan('database');
        $this->assertSame($database->getSpanName(), 'database');
        $database->updateName('tarantool');
        $this->assertSame($database->getSpanName(), 'tarantool');
    }

    public function testNestedSpans()
    {
        $tracer = new Tracer();

        $guard = $tracer->createSpan('guard.validate');
        $connection = $tracer->createSpan('guard.validate.connection');
        $procedure = $tracer->createSpan('guard.procedure.registration')->end();
        $connection->end();
        $policy = $tracer->createSpan('policy.describe')->end();

        $guard->end();

        $this->assertEquals($connection->getParentContext(), $guard->getContext());
        $this->assertEquals($procedure->getParentContext(), $connection->getContext());
        $this->assertEquals($policy->getParentContext(), $guard->getContext());

        $this->assertCount(5, $tracer->getSpans());
    }

    public function testCreateSpan()
    {
        $tracer = new Tracer();
        $global = $tracer->getActiveSpan();

        $mysql = $tracer->createSpan('mysql');
        $this->assertSame($tracer->getActiveSpan(), $mysql);
        $this->assertSame($global->getContext()->getTraceId(), $mysql->getContext()->getTraceId());
        $this->assertEquals($mysql->getParentContext(), $global->getContext());
        $this->assertNotNull($mysql->getStartTimestamp());
        $this->assertTrue($mysql->isRecording());
        $this->assertNull($mysql->getDuration());

        $mysql->end();
        $this->assertFalse($mysql->isRecording());
        $this->assertNotNull($mysql->getDuration());

        $duration = $mysql->getDuration();
        $this->assertSame($duration, $mysql->getDuration());
        $mysql->end();
        $this->assertGreaterThan($duration, $mysql->getDuration());

        $this->assertTrue($mysql->getStatus()->isStatusOK());
        
        // active span rolled back
        $this->assertSame($tracer->getActiveSpan(), $global);
        
        // active span should be kept for global span
        $global->end();
        $this->assertSame($tracer->getActiveSpan(), $global);
        $this->assertTrue($global->getStatus()->isStatusOK());
    }

    public function testStatusManipulation()
    {
        $tracer = new Tracer();

        $cancelled = $tracer->createSpan('cancelled');
        $cancelled->end(SpanStatus::CANCELLED);
        $this->assertFalse($cancelled->getStatus()->isStatusOK());
        $this->assertSame($cancelled->getStatus()->getCanonicalStatusCode(), SpanStatus::CANCELLED);
        $this->assertSame($cancelled->getStatus()->getStatusDescription(), SpanStatus::DESCRIPTION[SpanStatus::CANCELLED]);

        // code -1 shouldn't ever exist
        $noDescription = SpanStatus::new(-1);
        self::assertEquals(SpanStatus::DESCRIPTION[SpanStatus::UNKNOWN], $noDescription->getStatusDescription());

        $this->assertCount(2, $tracer->getSpans());
    }

    public function testSpanAttributesApi()
    {
        $span = (new Tracer())->getActiveSpan();

        // set attributes
        $span->replaceAttributes(['username' => 'nekufa']);

        // get attribute
        $this->assertEquals(new Attribute('username', 'nekufa'), $span->getAttribute('username'));

        // otherwrite
        $span->replaceAttributes(['email' => 'nekufa@gmail.com',]);

        // null attributes
        self::assertNull($span->getAttribute('username'));
        self::assertEquals(new Attribute('email', 'nekufa@gmail.com'), $span->getAttribute('email'));

        // set attribute
        $span->setAttribute('username', 'nekufa');
        self::assertEquals(new Attribute('username', 'nekufa'), $span->getAttribute('username'));
        $attributes = $span->getAttributes();
        self::assertCount(2, $attributes);
        self::assertEquals(new Attribute('email', 'nekufa@gmail.com'), $span->getAttribute('email'));
        self::assertEquals(new Attribute('username', 'nekufa'), $span->getAttribute('username'));

        // keep order
        $expected = [
            'a' => new Attribute('a', 1),
            'b' => new Attribute('b', 2),
        ];
        $span->replaceAttributes(['a' => 1, 'b' => 2]);

        $actual = \iterator_to_array($span->getAttributes());
        self::assertEquals($expected, $actual);

        // attribute update don't change the order
        $span->setAttribute('a', 3);
        $span->setAttribute('b', 4);

        $expected = [
            'a' => new Attribute('a', 3),
            'b' => new Attribute('b', 4),
        ];
        $actual = \iterator_to_array($span->getAttributes());
        self::assertEquals($expected, $actual);
    }

    public function testSetAttributeWhenNotRecording()
    {
        // todo: implement test
        $this->markTestIncomplete();
    }

    public function testEventRegistration()
    {
        $span = (new Tracer())->createSpan('database');
        $eventAttributes = new Attributes([
            'space' => 'guard.session',
            'id' => 67235,
        ]);
        $span->addEvent('select', $eventAttributes);

        $events = $span->getEvents();
        self::assertCount(1, $events);

        [$event] = \iterator_to_array($events);
        $this->assertSame($event->getName(), 'select');
        $attributes = new Attributes([
            'space' => 'guard.session',
            'id' => 67235,
        ]);
        self::assertEquals($attributes, $event->getAttributes());

        $span->addEvent('update')
                    ->setAttribute('space', 'guard.session')
                    ->setAttribute('id', 67235)
                    ->setAttribute('active_at', time());

        $this->assertCount(2, $span->getEvents());
    }

    public function testBuilder()
    {
        $spanContext = SpanContext::generate();
        $tracer = new Tracer([], $spanContext);

        $this->assertInstanceOf(Tracer::class, $tracer);
        $this->assertEquals($tracer->getActiveSpan()->getContext(), $spanContext);
    }

    public function testParentSpanContext()
    {
        $tracer = new Tracer();
        $global = $tracer->getActiveSpan();
        $request = $tracer->createSpan('request');
        $this->assertSame($request->getParentContext()->getSpanId(), $global->getContext()->getSpanId());
        $this->assertNull($global->getParentContext());
        $this->assertNotNull($request->getParentContext());
    }

    public function testZipkinConverter()
    {
        $tracer = new Tracer();
        $span = $tracer->createSpan('guard.validate');
        $span->setAttribute('service', 'guard');
        $span->addEvent('validators.list', new Attributes(['job' => 'stage.updateTime']));
        $span->end();

        $method = new ReflectionMethod(ZipkinExporter::class, 'convertSpan');
        $method->setAccessible(true);

        $exporter = new ZipkinExporter(
            'test.name',
            'http://host:123/path'
        );

        $row = $method->invokeArgs($exporter, ['span' => $span]);
        $this->assertSame($row['name'], $span->getSpanName());

        self::assertCount(1, $row['tags']);
        self::assertEquals($span->getAttribute('service')->getValue(), $row['tags']['service']);

        self::assertCount(1, $row['annotations']);
        [$annotation] = $row['annotations'];
        self::assertEquals('validators.list', $annotation['value']);

        [$event] = \iterator_to_array($span->getEvents());
        self::assertEquals($event->getTimestamp(), $annotation['timestamp']);
    }
}
