<?php

namespace AffiWave\WooCommerce\Tests\Unit;

use AffiWave\WooCommerce\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';

    public function testAcceptsSignatureMadeLikeAffiWave(): void
    {
        $body = '{"event":"coupon.created","data":{"coupon_id":1}}';
        $header = 't=1000,v1=' . hash_hmac('sha256', '1000.' . $body, self::SECRET);

        self::assertTrue(Signature::verify($header, $body, self::SECRET, 1100));
    }

    public function testRejectsChangedBodyWrongSecretAndOldTimestamp(): void
    {
        $body = '{"a":1}';
        $header = Signature::header(1000, $body, self::SECRET);

        self::assertFalse(Signature::verify($header, '{"a":2}', self::SECRET, 1000));
        self::assertFalse(Signature::verify($header, $body, 'other', 1000));
        self::assertFalse(Signature::verify($header, $body, self::SECRET, 1000 + 301));
        self::assertTrue(Signature::verify($header, $body, self::SECRET, 1000 - 300));
    }

    public function testRejectsMalformedHeaderAndEmptySecret(): void
    {
        self::assertFalse(Signature::verify('', '{}', self::SECRET, 1));
        self::assertFalse(Signature::verify('v1=abc', '{}', self::SECRET, 1));
        self::assertFalse(Signature::verify('t=abc,v1=abc', '{}', self::SECRET, 1));
        self::assertFalse(Signature::verify(Signature::header(1, '{}', ''), '{}', '', 1));
    }
}
