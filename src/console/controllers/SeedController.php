<?php

namespace newism\notfoundredirects\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\NotFoundUri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\models\Referrer;
use newism\notfoundredirects\NotFoundRedirects;
use yii\console\ExitCode;

class SeedController extends Controller
{
    private int $total404s = 100;
    private int $totalRedirects = 75;
    private int $siteId = 1;

    private array $fake404s = [];
    private array $fakeRedirects = [];

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['404s']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            '404s' => 'n',
        ]);
    }

    /**
     * Seed the database with fake 404 records and redirects.
     *
     * ```
     * craft not-found-redirects/seed/run
     * craft not-found-redirects/seed/run 50
     * ```
     */
    public function actionRun(int $count = 100): int
    {
        $this->total404s = $count;
        $this->totalRedirects = (int)floor($count * 0.75);

        $this->stdout("Seeding database with {$this->total404s} 404s and {$this->totalRedirects} redirects...\n\n");

        $this->clean();
        $this->seed404s();
        $this->seedRedirects();
        $this->seedReferrers();

        $this->stdout("\nDone! Seeded " . count($this->fake404s) . " 404s, " . count($this->fakeRedirects) . " redirects.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Remove all seed data from the database.
     *
     * ```
     * craft not-found-redirects/seed/clean
     * ```
     */
    public function actionClean(): int
    {
        $this->clean();
        $this->stdout("Done! All seed data removed.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function clean(): void
    {
        $this->stdout("Cleaning existing seed data...\n");

        Craft::$app->getDb()->createCommand()
            ->delete(Table::REFERRERS)
            ->execute();

        Craft::$app->getDb()->createCommand()
            ->delete(Table::NOTES)
            ->execute();

        Craft::$app->getDb()->createCommand()
            ->delete(Table::NOT_FOUND_URIS)
            ->execute();

        Craft::$app->getDb()->createCommand()
            ->delete(Table::REDIRECTS)
            ->execute();

        $this->stdout("  - Deleted all 404s, redirects, notes, and referrers\n");
    }

    private function seed404s(): void
    {
        $this->stdout("Creating {$this->total404s} 404 records...\n");

        $now = new DateTime();
        $thirtyDaysAgo = (clone $now)->sub(new DateInterval('P30D'));
        $totalSeconds = $now->getTimestamp() - $thirtyDaysAgo->getTimestamp();

        $uriTemplates = $this->getUriTemplates();
        $handledCount = (int)floor($this->total404s * 0.75);

        $notFoundService = NotFoundRedirects::getInstance()->getNotFoundUriService();

        for ($i = 1; $i <= $this->total404s; $i++) {
            $handled = ($i <= $handledCount);

            $randomOffset = random_int(0, $totalSeconds);
            $createdAt = (clone $thirtyDaysAgo)->add(new DateInterval('PT' . $randomOffset . 'S'));

            $uri = $this->generateUri($uriTemplates, $i);

            $notFound = new NotFoundUri([
                'uri' => $uri,
                'siteId' => $this->siteId,
                'hitCount' => random_int(1, 50),
                'hitLastTime' => $createdAt,
                'handled' => $handled,
                'source' => 'seed',
                'dateCreated' => $createdAt,
                'dateUpdated' => $createdAt,
            ]);

            $notFoundService->saveNotFoundUri($notFound);
            $this->fake404s[$i] = $notFound;

            if ($i % 20 === 0) {
                $this->stdout("  - Created {$i}/{$this->total404s} 404s\n");
            }
        }
    }

    private function seedRedirects(): void
    {
        $this->stdout("\nCreating {$this->totalRedirects} redirects...\n");

        $redirectService = NotFoundRedirects::getInstance()->getRedirectService();
        $notFoundService = NotFoundRedirects::getInstance()->getNotFoundUriService();

        $handled404s = array_filter($this->fake404s, fn($r) => $r->handled);
        $handled404Keys = array_keys($handled404s);
        shuffle($handled404Keys);
        $handled404Keys = array_slice($handled404Keys, 0, $this->totalRedirects);

        $redirectCount = min($this->totalRedirects, count($handled404Keys));
        $statusCodes = [301, 302, 307, 410];
        $destinations = ['/blog/new-post', '/news', '/products', '/services', '/about', '/', '/contact', '/blog'];

        for ($i = 0; $i < $redirectCount; $i++) {
            $original404 = $this->fake404s[$handled404Keys[$i]];
            if (!$original404) {
                continue;
            }

            $createdAt = (clone $original404->hitLastTime)->add(
                new DateInterval('PT' . random_int(60, 60 * 60 * 24 * 7) . 'S')
            );

            $matchType = $this->getRandomMatchType();
            $from = $matchType === 'regex'
                ? str_replace(['<', '>'], ['<', '>'], $original404->uri)
                : $original404->uri;

            $to = $destinations[array_rand($destinations)] . '/' . basename($original404->uri, '-' . substr(basename($original404->uri), -3));

            $redirect = new Redirect([
                'from' => $from,
                'to' => $to,
                'toType' => 'url',
                'statusCode' => $statusCodes[array_rand($statusCodes)],
                'priority' => random_int(0, 10),
                'enabled' => true,
                'regexMatch' => $matchType === 'regex',
                'systemGenerated' => random_int(0, 10) < 2,
                'hitCount' => random_int(0, 20),
                'hitLastTime' => random_int(0, 5) > 2
                    ? (clone $createdAt)->add(new DateInterval('PT' . random_int(1, 86400 * 7) . 'S'))
                    : null,
                'dateCreated' => $createdAt,
                'dateUpdated' => $createdAt,
            ]);

            $redirectService->saveRedirect($redirect);
            $this->fakeRedirects[] = $redirect;

            $original404->redirectId = $redirect->id;
            $original404->handled = true;
            $notFoundService->saveNotFoundUri($original404);

            if (($i + 1) % 20 === 0) {
                $this->stdout("  - Created " . ($i + 1) . "/{$redirectCount} redirects\n");
            }
        }
    }

    private function seedReferrers(): void
    {
        $this->stdout("Creating referrers...\n");

        $referrerDomains = [
            'https://www.google.com/search?q=',
            'https://twitter.com/',
            'https://www.facebook.com/',
            'https://www.linkedin.com/',
            'https://old-site.com/',
            'https://archive.org/',
            'https://www.bing.com/search?q=',
        ];

        $count = 0;
        foreach ($this->fake404s as $notFound) {
            $numReferrers = random_int(0, 5);
            $slug = basename($notFound->uri);

            for ($r = 0; $r < $numReferrers; $r++) {
                $domain = $referrerDomains[array_rand($referrerDomains)];
                $referrerUrl = $domain . urlencode($slug);
                $createdAt = (clone $notFound->hitLastTime)->sub(
                    new DateInterval('PT' . random_int(1, 3600) . 'S')
                );

                $referrer = new Referrer([
                    'notFoundId' => $notFound->id,
                    'referrer' => $referrerUrl,
                    'hitCount' => random_int(1, 10),
                    'hitLastTime' => $createdAt,
                    'dateCreated' => $createdAt,
                    'dateUpdated' => $createdAt,
                ]);

                $data = $referrer->toArray();
                $data['dateCreated'] = Db::prepareDateForDb($data['dateCreated']);
                $data['dateUpdated'] = Db::prepareDateForDb($data['dateUpdated']);
                $data['hitLastTime'] = Db::prepareDateForDb($data['hitLastTime']);
                unset($data['id']);

                Craft::$app->getDb()->createCommand()->upsert(
                    Table::REFERRERS,
                    $data,
                )->execute();

                $count++;
            }
        }

        $this->stdout("  - Created {$count} referrers\n");
    }

    private function getUriTemplates(): array
    {
        return [
            '/blog/old-post-{n}',
            '/news/{slug}-{year}',
            '/products/{category}/{slug}',
            '/pages/{slug}',
            '/legacy/{section}/{slug}',
            '/images/{dir}/{name}.{ext}',
            '/assets/{type}/{file}',
            '/{year}/{month}/{slug}',
            '/category/{slug}',
            '/tag/{tag}',
            '/author/{author}',
            '/{slug}',
            '/{slug}.html',
            '/{slug}.php',
            '/{dir}/{slug}',
        ];
    }

    private function generateUri(array $templates, int $index): string
    {
        $template = $templates[array_rand($templates)];
        $slug = $this->generateSlug($index);
        $replacements = [
            '{n}' => $index,
            '{slug}' => $slug,
            '{year}' => random_int(2015, 2024),
            '{month}' => str_pad(random_int(1, 12), 2, '0', STR_PAD_LEFT),
            '{category}' => $this->randomCategory(),
            '{section}' => $this->randomSection(),
            '{dir}' => $this->randomDir(),
            '{name}' => $this->generateFileName($index),
            '{ext}' => $this->randomExtension(),
            '{type}' => $this->randomType(),
            '{tag}' => $this->generateTag($index),
            '{author}' => $this->generateAuthor($index),
            '{file}' => $this->generateFileName($index),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function getRandomMatchType(): string
    {
        $rand = random_int(1, 10);
        if ($rand <= 5) {
            return 'exact';
        }
        if ($rand <= 8) {
            return 'named';
        }
        return 'regex';
    }

    private function generateSlug(int $index): string
    {
        $words = [
            'hello-world', 'welcome-visitors', 'about-us', 'contact-page',
            'old-post', 'deprecated-content', 'moved-page', 'archived-entry',
            'missing-resource', 'removed-item', 'legacy-data', 'previous-post',
            'sample-page', 'test-content', 'temp-file', 'draft-document',
        ];
        return $words[$index % count($words)] . '-' . $index;
    }

    private function generateFileName(int $index): string
    {
        $names = ['logo', 'banner', 'image', 'icon', 'photo', 'graphic', 'header', 'background'];
        return $names[array_rand($names)] . '-' . $index;
    }

    private function generateTag(int $index): string
    {
        $tags = ['craft', 'cms', 'php', 'tutorial', 'guide', 'news', 'update', 'featured'];
        return $tags[$index % count($tags)] . '-' . $index;
    }

    private function generateAuthor(int $index): string
    {
        $authors = ['john-smith', 'jane-doe', 'admin', 'editor', 'contributor'];
        return $authors[$index % count($authors)] . '-' . $index;
    }

    private function randomCategory(): string
    {
        $categories = ['news', 'products', 'services', 'blog', 'resources', 'docs'];
        return $categories[array_rand($categories)];
    }

    private function randomSection(): string
    {
        $sections = ['legacy', 'archive', 'old', 'deprecated', 'migrated'];
        return $sections[array_rand($sections)];
    }

    private function randomDir(): string
    {
        $dirs = ['images', 'assets', 'files', 'uploads', 'media', 'css', 'js'];
        return $dirs[array_rand($dirs)];
    }

    private function randomExtension(): string
    {
        $exts = ['png', 'jpg', 'gif', 'svg', 'css', 'js', 'woff', 'woff2'];
        return $exts[array_rand($exts)];
    }

    private function randomType(): string
    {
        $types = ['images', 'styles', 'scripts', 'fonts', 'media'];
        return $types[array_rand($types)];
    }
}
