<?php

namespace AffiWave\WooCommerce\Tests\Unit;

use AffiWave\WooCommerce\IntegrationKey;
use PHPUnit\Framework\TestCase;

final class IntegrationKeyTest extends TestCase
{
    public function testDecodesKeyMadeLikeAffiWave(): void
    {
        // Same algorithm as AffiWave (App\Service\Integration\WordPressConnector::integrationKey()).
        $json = '{"u":"https://affiwave.com/","k":"ak_test.dummy","s":"whsec_abc","p":7}';
        $key = 'awi1_' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        self::assertSame([
            'api_url' => 'https://affiwave.com',
            'api_key' => 'ak_test.dummy',
            'webhook_secret' => 'whsec_abc',
            'program_id' => '7',
        ], IntegrationKey::decode($key));
    }

    public function testIgnoresWhitespaceFromCopying(): void
    {
        $key = IntegrationKey::encode('https://affiwave.com', 'ak_x.y', '', null);

        self::assertSame('', IntegrationKey::decode("  " . chunk_split($key, 20, "\n") . " ")['program_id']);
    }

    public function testRejectsMalformedKeys(): void
    {
        self::assertNull(IntegrationKey::decode(''));
        self::assertNull(IntegrationKey::decode('ak_test.dummy'));
        self::assertNull(IntegrationKey::decode('awi1_###'));
        self::assertNull(IntegrationKey::decode('awi1_' . base64_encode('"text"')));
        self::assertNull(IntegrationKey::decode(IntegrationKey::encode('ftp://x', 'ak_x.y', 's', 1)));
        self::assertNull(IntegrationKey::decode(IntegrationKey::encode('https://affiwave.com', '', 's', 1)));
    }
}
