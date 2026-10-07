<?php

namespace AffiWave\WooCommerce\Tests\Unit;

use AffiWave\WooCommerce\CouponSync;
use PHPUnit\Framework\TestCase;

final class CouponMappingTest extends TestCase
{
    public function testNormalizesWebhookData(): void
    {
        $c = CouponSync::normalize([
            'coupon_id' => 42, 'code' => 'ANNA20', 'discount_type' => 'fixed', 'discount_value' => '50.00',
            'amount_basis' => 'gross', 'currency' => 'eur', 'status' => 'active', 'usage_limit' => 100,
            'expires_at' => '2030-01-31T00:00:00+01:00', 'campaign_id' => 7, 'program_id' => 3, 'affiliate_id' => 12,
        ]);

        self::assertSame(42, $c['id']);
        self::assertSame('ANNA20', $c['code']);
        self::assertSame('50.00', $c['value']);
        self::assertSame('EUR', $c['currency']);
        self::assertSame(3, $c['program_id']);
        self::assertSame(7, $c['campaign_id']);
        self::assertSame(100, $c['usage_limit']);
    }

    public function testNormalizesApiResourceWithIris(): void
    {
        $c = CouponSync::normalize([
            'id' => 5, 'code' => 'Jan10', 'discountType' => 'percentage', 'discountValue' => '10',
            'status' => 'disabled', 'usageLimit' => null, 'expiresAt' => null,
            'campaign' => '/api/campaigns/11', 'affiliate' => '/api/affiliates/3', 'currency' => 'PLN',
        ]);

        self::assertSame('Jan10', $c['code']);
        self::assertSame('10.00', $c['value']);
        self::assertNull($c['program_id']);
        self::assertSame(11, $c['campaign_id']);
        self::assertSame(3, $c['affiliate_id']);
        self::assertNull($c['usage_limit']);
    }

    public function testRejectsIncompleteCoupon(): void
    {
        self::assertNull(CouponSync::normalize(['code' => 'X']));
        self::assertNull(CouponSync::normalize(['coupon_id' => 1, 'code' => ' ']));
    }

    public function testMapsToWooCommerceProps(): void
    {
        $now = strtotime('2026-10-07T12:00:00Z');
        $base = CouponSync::normalize(['coupon_id' => 1, 'code' => 'A', 'discount_type' => 'percentage', 'discount_value' => '20', 'status' => 'active']);

        $p = CouponSync::woo_props($base, $now);
        self::assertSame('percent', $p['discount_type']);
        self::assertSame('20.00', $p['amount']);
        self::assertSame(0, $p['usage_limit']);
        self::assertNull($p['date_expires']);
        self::assertSame('publish', $p['post_status']);

        $fixed = ['type' => 'fixed', 'usage_limit' => 5, 'expires_at' => '2030-01-01T00:00:00+00:00'] + $base;
        $p = CouponSync::woo_props($fixed, $now);
        self::assertSame('fixed_cart', $p['discount_type']);
        self::assertSame(5, $p['usage_limit']);
        self::assertSame(strtotime('2030-01-01T00:00:00+00:00'), $p['date_expires']);

        self::assertSame('draft', CouponSync::woo_props(['status' => 'disabled'] + $base, $now)['post_status']);
        self::assertSame('draft', CouponSync::woo_props(['expires_at' => '2026-01-01T00:00:00+00:00'] + $base, $now)['post_status']);
    }
}
