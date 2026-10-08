<?php

namespace AffiWave\WooCommerce\Tests\Unit;

use AffiWave\WooCommerce\Release;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase
{
    private const ZIP = 'https://github.com/websystemspl/affiwave-woocommerce/releases/download/v0.3.0/affiwave-woocommerce.zip';

    /** @return array<string, mixed> shape of GET /repos/{repo}/releases/latest */
    private function release(array $override = []): array
    {
        return $override + [
            'tag_name' => 'v0.3.0',
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://github.com/websystemspl/affiwave-woocommerce/releases/tag/v0.3.0',
            'published_at' => '2026-10-08T12:00:00Z',
            'body' => "### 0.3.0\n- Updates from GitHub.",
            'assets' => [
                ['name' => 'source.tar.gz', 'browser_download_url' => 'https://example.com/source.tar.gz'],
                ['name' => 'affiwave-woocommerce.zip', 'browser_download_url' => self::ZIP],
            ],
        ];
    }

    public function testReadsVersionAndZipFromLatestRelease(): void
    {
        $release = Release::from_github($this->release());

        self::assertSame('0.3.0', $release['version']);
        self::assertSame(self::ZIP, $release['package']);
        self::assertTrue(Release::is_newer($release, '0.2.0'));
        self::assertFalse(Release::is_newer($release, '0.3.0'));
        self::assertFalse(Release::is_newer($release, '0.10.0'));
    }

    public function testIgnoresDraftsPrereleasesBadTagsAndMissingZip(): void
    {
        self::assertNull(Release::from_github($this->release(['draft' => true])));
        self::assertNull(Release::from_github($this->release(['prerelease' => true])));
        self::assertNull(Release::from_github($this->release(['tag_name' => 'nightly'])));
        self::assertNull(Release::from_github($this->release(['assets' => [['name' => 'other.zip', 'browser_download_url' => self::ZIP]]])));
        self::assertNull(Release::from_github($this->release(['assets' => [['name' => 'affiwave-woocommerce.zip', 'browser_download_url' => 'http://insecure/x.zip']]])));
        self::assertNull(Release::from_github([]));
    }

    public function testNotesMarkdownBecomesEscapedHtml(): void
    {
        $html = Release::notes_html("### 0.3.0\n**Install:** `zip` from https://github.com/x\n- one <b>\n- two\n\nend");

        self::assertSame(
            '<h4>0.3.0</h4><p><strong>Install:</strong> <code>zip</code> from <a href="https://github.com/x">https://github.com/x</a></p>'
            .'<ul><li>one &lt;b&gt;</li><li>two</li></ul><p>end</p>',
            $html,
        );
    }
}
