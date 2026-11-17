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
        ], IntegrationKey::decode($key));
    }

    public function testKeyWithoutProgramFieldIsAccepted(): void
    {
        $json = '{"u":"https://affiwave.com","k":"ak_test.dummy","s":"whsec_abc"}';
        $key = 'awi1_' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        self::assertSame([
            'api_url' => 'https://affiwave.com',
            'api_key' => 'ak_test.dummy',
            'webhook_secret' => 'whsec_abc',
        ], IntegrationKey::decode($key));
        self::assertSame(IntegrationKey::decode($key), IntegrationKey::decode(IntegrationKey::encode('https://affiwave.com', 'ak_test.dummy', 'whsec_abc')));
    }

    public function testLegacyProgramFieldIsIgnored(): void
    {
        $withProgram = IntegrationKey::decode(IntegrationKey::encode('https://affiwave.com', 'ak_x.y', 's', 7));
        $without = IntegrationKey::decode(IntegrationKey::encode('https://affiwave.com', 'ak_x.y', 's'));

        self::assertSame($without, $withProgram);
        self::assertArrayNotHasKey('program_id', $withProgram);
    }

    public function testIgnoresWhitespaceFromCopying(): void
    {
        $key = IntegrationKey::encode('https://affiwave.com', 'ak_x.y', '');

        self::assertSame('ak_x.y', IntegrationKey::decode("  " . chunk_split($key, 20, "\n") . " ")['api_key']);
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

    public function testMasksSecretsWithoutRevealingThem(): void
    {
        self::assertSame('ak_1a2b3c4d.••••••••', IntegrationKey::mask('ak_1a2b3c4d.' . str_repeat('f', 48)));
        self::assertSame('whsec_••••••••', IntegrationKey::mask('whsec_' . str_repeat('a', 40)));
        self::assertSame('ab••••••••', IntegrationKey::mask('abcdefg'));
        self::assertSame('', IntegrationKey::mask('  '));
    }
}
