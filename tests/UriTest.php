<?php

namespace newism\notfoundredirects\tests;

use Craft;
use newism\notfoundredirects\helpers\Uri;
use PHPUnit\Framework\TestCase;

final class UriTest extends TestCase
{
    private $originalApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalApp = Craft::$app;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->originalApp;
        parent::tearDown();
    }

    // ── strip() ──────────────────────────────────────────────────────

    public function testStripRemovesLeadingSlash(): void
    {
        $this->assertSame('blog/post', Uri::strip('/blog/post'));
    }

    public function testStripLeavesRelativePathUnchanged(): void
    {
        $this->assertSame('blog/post', Uri::strip('blog/post'));
    }

    public function testStripHandlesNull(): void
    {
        $this->assertSame('', Uri::strip(null));
    }

    // ── extractPath() ───────────────────────────────────────────────

    public function testExtractPathStripsSchemeHostAndQueryFromFullUrl(): void
    {
        $this->assertSame('blog/post', Uri::extractPath('https://example.com/blog/post?q=1'));
    }

    public function testExtractPathStripsLeadingSlashFromRelativePath(): void
    {
        $this->assertSame('blog/post', Uri::extractPath('/blog/post'));
    }

    public function testExtractPathLeavesBarePathUnchanged(): void
    {
        $this->assertSame('blog/post', Uri::extractPath('blog/post'));
    }

    // ── display() ────────────────────────────────────────────────────

    public function testDisplayReturnsSlashForNull(): void
    {
        $this->assertSame('/', Uri::display(null));
    }

    public function testDisplayReturnsSlashForEmptyString(): void
    {
        $this->assertSame('/', Uri::display(''));
    }

    public function testDisplayPassesThroughRelativePath(): void
    {
        $this->assertSame('blog/post', Uri::display('blog/post'));
    }

    // ── stripSiteBasePath() ─────────────────────────────────────────

    public function testStripSiteBasePathStripsFullUrlBase(): void
    {
        Craft::$app = $this->fakeAppWithSites(['https://example.com/en/']);

        $this->assertSame('old-blog', Uri::stripSiteBasePath('en/old-blog', 1));
    }

    public function testStripSiteBasePathStripsHostlessBase(): void
    {
        Craft::$app = $this->fakeAppWithSites(['/en/']);

        $this->assertSame('old-blog', Uri::stripSiteBasePath('en/old-blog', 1));
    }

    public function testStripSiteBasePathDoesNotStripContentOnPrefixLessSite(): void
    {
        // A root-based site ("/") has no base path segment. Content on that
        // site that happens to start with "en/" is legitimate and must not
        // be stripped — only an actual matching base path should trigger.
        Craft::$app = $this->fakeAppWithSites(['/']);

        $this->assertSame('en/some-real-page', Uri::stripSiteBasePath('en/some-real-page', 1));
    }

    public function testStripSiteBasePathHandlesNullBaseUrlWithoutError(): void
    {
        // Headless site — getBaseUrl() returns null. Must not throw and
        // must not strip anything.
        Craft::$app = $this->fakeAppWithSites([null]);

        $this->assertSame('en/old-blog', Uri::stripSiteBasePath('en/old-blog', 1));
    }

    public function testStripSiteBasePathTriesAllSitesWhenSiteIdIsNull(): void
    {
        Craft::$app = $this->fakeAppWithSites(['/', '/en/']);

        $this->assertSame('old-blog', Uri::stripSiteBasePath('en/old-blog', null));
    }

    /**
     * Builds a fake Craft::$app exposing only what stripSiteBasePath() needs
     * (getSites()->getSiteById() / getAllSites()), avoiding a full Craft
     * application bootstrap for what is otherwise pure string-handling logic.
     *
     * @param array<string|null> $baseUrls
     */
    private function fakeAppWithSites(array $baseUrls): object
    {
        $sites = [];
        foreach (array_values($baseUrls) as $index => $baseUrl) {
            $siteId = $index + 1;
            $sites[$siteId] = new class ($siteId, $baseUrl) {
                public function __construct(
                    public readonly int $id,
                    private readonly ?string $baseUrl,
                ) {
                }

                public function getBaseUrl(): ?string
                {
                    return $this->baseUrl;
                }
            };
        }

        $sitesService = new class ($sites) {
            public function __construct(private readonly array $sites)
            {
            }

            public function getAllSites(): array
            {
                return array_values($this->sites);
            }

            public function getSiteById(int $id): ?object
            {
                return $this->sites[$id] ?? null;
            }
        };

        return new class ($sitesService) {
            public function __construct(private readonly object $sitesService)
            {
            }

            public function getSites(): object
            {
                return $this->sitesService;
            }
        };
    }
}
