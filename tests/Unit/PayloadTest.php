<?php

namespace Omaresmael\Thread\Tests\Unit;

use Illuminate\Support\Facades\Context;
use Omaresmael\Thread\Payload;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\OrderFailed;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\ParentCtorException;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\Unrebuildable;
use Omaresmael\Thread\Tests\TestCase;
use RuntimeException;

class PayloadTest extends TestCase
{
    public function test_round_trips_values_including_binary(): void
    {
        $bin = random_bytes(64);
        $this->assertSame($bin, Payload::value(Payload::decode(Payload::encode(Payload::success($bin), 1))));
        $this->assertSame(['a' => 1], Payload::value(Payload::success(['a' => 1])));
    }

    public function test_encode_adds_memory(): void
    {
        $data = Payload::decode(Payload::encode(Payload::success(1), 4096));

        $this->assertSame(4096, $data['memory']);
        $this->assertTrue($data['successful']);
    }

    public function test_rebuilds_custom_constructor_exception(): void
    {
        try {
            Payload::value(Payload::failure(new OrderFailed('A-17', 3)));
            $this->fail('no exception');
        } catch (OrderFailed $e) {
            $this->assertSame(['A-17', 3], [$e->orderId, $e->attempt]);
        }
    }

    public function test_rebuilds_message_only_and_parent_constructor_exceptions(): void
    {
        $this->expectExceptionObject(new ParentCtorException('nope'));
        Payload::value(Payload::failure(new ParentCtorException('nope')));
    }

    public function test_unrebuildable_exception_falls_back_to_runtime_exception(): void
    {
        try {
            Payload::value(Payload::failure(new Unrebuildable('cannot rebuild me', 7)));
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            // Must be the fallback itself, not the (RuntimeException) fixture.
            $this->assertNotInstanceOf(Unrebuildable::class, $e);
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame(Unrebuildable::class.': cannot rebuild me', $e->getMessage());
        }
    }

    public function test_payload_carries_context(): void
    {
        Context::add('trace', 'abc');
        $payload = unserialize(Payload::task(fn () => 1));
        Context::flush();
        Context::hydrate($payload['context']);
        $this->assertSame('abc', Context::get('trace'));
    }
}
