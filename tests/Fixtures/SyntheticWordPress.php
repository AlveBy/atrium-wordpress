<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Fixtures;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;

/**
 * A small made-up WordPress database: the tables the importer reads, filled
 * with invented content.
 *
 * - posts (classic and block editor, drafts, a trash, a revision, a scheduled
 *   and a private one, one with broken encoding, one whose author and
 *   thumbnail do not exist), pages with a tree (and a child of a missing
 *   page), attachments and a "recipe" post type with ACF fields;
 * - categories (with a child), tags, a custom taxonomy and an internal menu
 *   term, SEO data of Yoast and Rank Math;
 * - Russian translations through WPML or Polylang, or a single Russian site;
 * - an attachment's metadata and an ACF value broken the way a careless
 *   search and replace of the domain breaks serialized strings.
 *
 * Term ids and term taxonomy ids differ on purpose (taxonomy id = term id +
 * TT_OFFSET), so code that mixes them up fails.
 *
 * Built with the schema builder, so it runs on any database Laravel does.
 */
final class SyntheticWordPress
{
    public const OLD_URL = 'https://old.example.com';

    /** Difference between term_taxonomy_id and term_id. */
    public const TT_OFFSET = 100;

    // Users.
    public const USER_ALICE = 1;

    public const USER_BORIS = 2;

    /** A subscriber without posts. */
    public const USER_CAROL = 3;

    /** The author of a post, who is not in the users table. */
    public const USER_MISSING = 99;

    // Terms (term_id). Add TT_OFFSET for the term_taxonomy_id.
    public const TERM_UNCATEGORIZED = 1;

    public const TERM_NEWS = 2;

    /** A child of News. */
    public const TERM_LOCAL = 3;

    /** The Russian translation of News (not there for "none"). */
    public const TERM_NOVOSTI = 4;

    public const TERM_LARAVEL = 5;

    public const TERM_PHP = 6;

    public const TERM_ITALIAN = 7;

    public const TERM_RUSSIAN = 8;

    /** An internal nav_menu term, skipped by the importer. */
    public const TERM_MAIN_MENU = 9;

    /** Polylang only. */
    public const TERM_LANG_EN = 10;

    /** Polylang only. */
    public const TERM_LANG_RU = 11;

    // Posts.
    public const HELLO = 10;

    /** The Russian translation of HELLO. */
    public const HELLO_RU = 11;

    public const GUTENBERG = 12;

    public const DRAFT_IDEA = 13;

    /** A draft with the same slug as HELLO. */
    public const HELLO_AGAIN = 14;

    public const TRASHED = 15;

    public const AUTO_DRAFT = 16;

    public const REVISION = 17;

    /** A title in Windows-1251 and a content that was encoded twice. */
    public const ENCODING = 18;

    public const SCHEDULED = 20;

    public const PRIVATE_NOTE = 21;

    /** The author and the thumbnail are not there. */
    public const ORPHAN = 22;

    // Pages.
    public const ABOUT = 30;

    /** The Russian translation of ABOUT. */
    public const ABOUT_RU = 31;

    public const TEAM = 32;

    public const HISTORY = 33;

    /** The parent (999) is not there. */
    public const LOST_CHILD = 34;

    public const CONTACTS = 35;

    // Recipes (a custom post type) and their ACF.
    public const BORSCHT = 40;

    /** The Russian translation of BORSCHT. */
    public const BORSCHT_RU = 41;

    public const PIZZA = 42;

    public const ACF_GROUP = 60;

    public const ACF_STEPS = 69;

    public const ACF_STEP_TEXT = 70;

    // Attachments.
    public const PHOTO = 50;

    public const BORSCHT_PHOTO = 51;

    /** The file is not on disk. */
    public const MISSING_PHOTO = 52;

    /** Not used by any post. */
    public const MANUAL = 53;

    /** An id of no post at all. */
    public const NO_SUCH_POST = 999;

    // What the broken strings must become.
    public const ENCODING_TITLE = 'Тест кодировки';

    public const ENCODING_CONTENT = 'Двойная кодировка';

    /** The slug of HELLO_RU as WordPress stores it. */
    public const HELLO_RU_SLUG_ENCODED = '%d0%bf%d1%80%d0%b8%d0%b2%d0%b5%d1%82-%d0%bc%d0%b8%d1%80';

    /** Vegan has 5 characters, the length says 6. */
    public const BROKEN_LABELS = 'a:2:{i:0;s:6:"vegan";i:1;s:5:"quick";}';

    public const HELLO_CONTENT = "First paragraph.\n\nSecond paragraph\nwith a line break.";

    public const GUTENBERG_CONTENT = "<!-- wp:paragraph -->\n<p>Block paragraph.</p>\n<!-- /wp:paragraph -->\n\n"
        .'<!-- wp:image {"id":50} -->'."\n"
        .'<figure class="wp-block-image"><img src="https://old.example.com/wp-content/uploads/2024/05/photo-300x200.jpg" alt=""/></figure>'."\n"
        .'<!-- /wp:image -->';

    /** ACF field name => field key. */
    public const ACF_KEYS = [
        'cooking_time' => 'field_cooking_time',
        'difficulty' => 'field_difficulty',
        'ingredients' => 'field_ingredients',
        'spicy' => 'field_spicy',
        'served_on' => 'field_served_on',
        'photo' => 'field_photo',
        'gallery' => 'field_gallery',
        'labels' => 'field_labels',
        'steps' => 'field_steps',
        'step_text' => 'field_step_text',
        'notes' => 'field_notes',
    ];

    /**
     * Creates the tables and fills them.
     *
     * @param  string  $languages  'wpml', 'polylang' or 'none' (a single Russian site)
     */
    public static function install(Connection $db, string $prefix = 'wp_', string $languages = 'wpml'): void
    {
        if (! in_array($languages, ['wpml', 'polylang', 'none'], true)) {
            throw new InvalidArgumentException("Unknown language plugin [{$languages}].");
        }

        self::createTables($db, $prefix, $languages);

        // Without a language plugin there are no translations at all.
        $skip = $languages === 'none' ? [self::HELLO_RU, self::ABOUT_RU, self::BORSCHT_RU] : [];

        [$terms, $taxonomies, $termRelations] = self::terms($languages);
        $postRelations = self::postRelations($languages);
        $posts = array_values(array_filter(self::posts(), fn (array $post): bool => ! in_array($post['ID'], $skip, true)));
        $meta = array_values(array_filter(self::postMeta(), fn (array $row): bool => ! in_array($row['post_id'], $skip, true)));
        $postRelations = array_values(array_filter($postRelations, fn (array $row): bool => ! in_array($row['object_id'], $skip, true)));

        // WordPress counts the published posts of a term.
        $published = [];
        foreach ($posts as $post) {
            $published[$post['ID']] = $post['post_status'] === 'publish';
        }
        $counts = [];
        foreach ($postRelations as $row) {
            if ($published[$row['object_id']] ?? false) {
                $counts[$row['term_taxonomy_id']] = ($counts[$row['term_taxonomy_id']] ?? 0) + 1;
            }
        }
        foreach ($taxonomies as &$taxonomy) {
            $taxonomy['count'] = $counts[$taxonomy['term_taxonomy_id']] ?? 0;
        }
        unset($taxonomy);

        self::insert($db, $prefix.'users', self::users());
        self::insert($db, $prefix.'usermeta', self::userMeta($prefix));
        self::insert($db, $prefix.'options', self::options($languages));
        self::insert($db, $prefix.'terms', $terms);
        self::insert($db, $prefix.'term_taxonomy', $taxonomies);
        self::insert($db, $prefix.'term_relationships', array_merge($postRelations, $termRelations));
        self::insert($db, $prefix.'termmeta', self::termMeta());
        self::insert($db, $prefix.'posts', $posts);
        self::insert($db, $prefix.'postmeta', $meta);

        if ($languages === 'wpml') {
            self::insert($db, $prefix.'icl_translations', self::wpmlTranslations());
        }
    }

    /**
     * The files of wp-content/uploads that the attachments point at, except
     * MISSING_PHOTO, whose file is gone from this "site".
     */
    public static function writeUploads(string $uploadsDir): void
    {
        $files = [
            '2024/05/photo.jpg' => "\xFF\xD8\xFF\xE0synthetic-photo",
            '2024/05/photo-300x200.jpg' => "\xFF\xD8\xFF\xE0synthetic-photo-medium",
            '2024/06/borscht.png' => "\x89PNG\r\n\x1a\nsynthetic-borscht",
            'docs/manual.pdf' => "%PDF-1.4\nsynthetic-manual\n",
        ];

        foreach ($files as $path => $bytes) {
            $file = rtrim($uploadsDir, '/').'/'.$path;
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $bytes);
        }
    }

    /**
     * The metadata of PHOTO as WordPress would save it.
     *
     * @return array<string, mixed>
     */
    public static function attachmentMetadata(): array
    {
        return [
            'width' => 1200,
            'height' => 800,
            'file' => '2024/05/photo.jpg',
            'sizes' => [
                'medium' => [
                    'file' => 'photo-300x200.jpg',
                    'width' => 300,
                    'height' => 200,
                    'mime-type' => 'image/jpeg',
                ],
            ],
            'image_meta' => ['credit' => self::OLD_URL],
        ];
    }

    /**
     * The same metadata after a search and replace of the domain that did not
     * fix the string lengths: unserialize() fails on it.
     */
    public static function brokenAttachmentMetadata(): string
    {
        return str_replace('old.example.com', 'new-much-longer.example.com', serialize(self::attachmentMetadata()));
    }

    /**
     * The title of ENCODING as the bytes the old database holds: Windows-1251,
     * which is not valid UTF-8.
     */
    public static function encodingTitle(): string
    {
        return mb_convert_encoding(self::ENCODING_TITLE, 'Windows-1251', 'UTF-8');
    }

    /**
     * The content of ENCODING encoded twice: the UTF-8 bytes read as
     * ISO-8859-1 and encoded to UTF-8 again.
     */
    public static function encodingContent(): string
    {
        return mb_convert_encoding(self::ENCODING_CONTENT, 'UTF-8', 'ISO-8859-1');
    }

    private static function createTables(Connection $db, string $prefix, string $languages): void
    {
        $schema = $db->getSchemaBuilder();

        $schema->create($prefix.'posts', function (Blueprint $t): void {
            $t->unsignedBigInteger('ID')->primary();
            $t->unsignedBigInteger('post_author')->default(0);
            $t->dateTime('post_date');
            $t->dateTime('post_date_gmt');
            $t->longText('post_content');
            $t->text('post_title');
            $t->text('post_excerpt');
            $t->string('post_status', 20)->default('publish');
            $t->string('comment_status', 20)->default('open');
            $t->string('ping_status', 20)->default('open');
            $t->string('post_password', 255)->default('');
            $t->string('post_name', 200)->default('');
            $t->text('to_ping');
            $t->text('pinged');
            $t->dateTime('post_modified');
            $t->dateTime('post_modified_gmt');
            $t->longText('post_content_filtered');
            $t->unsignedBigInteger('post_parent')->default(0);
            $t->string('guid', 255)->default('');
            $t->integer('menu_order')->default(0);
            $t->string('post_type', 20)->default('post');
            $t->string('post_mime_type', 100)->default('');
            $t->bigInteger('comment_count')->default(0);
        });

        $schema->create($prefix.'postmeta', function (Blueprint $t): void {
            $t->bigIncrements('meta_id');
            $t->unsignedBigInteger('post_id')->default(0);
            $t->string('meta_key', 255)->nullable();
            $t->longText('meta_value')->nullable();
        });

        $schema->create($prefix.'terms', function (Blueprint $t): void {
            $t->unsignedBigInteger('term_id')->primary();
            $t->string('name', 200)->default('');
            $t->string('slug', 200)->default('');
            $t->bigInteger('term_group')->default(0);
        });

        $schema->create($prefix.'term_taxonomy', function (Blueprint $t): void {
            $t->unsignedBigInteger('term_taxonomy_id')->primary();
            $t->unsignedBigInteger('term_id')->default(0);
            $t->string('taxonomy', 32)->default('');
            $t->longText('description');
            $t->unsignedBigInteger('parent')->default(0);
            $t->bigInteger('count')->default(0);
        });

        $schema->create($prefix.'term_relationships', function (Blueprint $t): void {
            $t->unsignedBigInteger('object_id')->default(0);
            $t->unsignedBigInteger('term_taxonomy_id')->default(0);
            $t->integer('term_order')->default(0);
            $t->primary(['object_id', 'term_taxonomy_id']);
        });

        $schema->create($prefix.'termmeta', function (Blueprint $t): void {
            $t->bigIncrements('meta_id');
            $t->unsignedBigInteger('term_id')->default(0);
            $t->string('meta_key', 255)->nullable();
            $t->longText('meta_value')->nullable();
        });

        $schema->create($prefix.'users', function (Blueprint $t): void {
            $t->unsignedBigInteger('ID')->primary();
            $t->string('user_login', 60)->default('');
            $t->string('user_pass', 255)->default('');
            $t->string('user_nicename', 50)->default('');
            $t->string('user_email', 100)->default('');
            $t->string('user_url', 100)->default('');
            $t->dateTime('user_registered');
            $t->string('user_activation_key', 255)->default('');
            $t->integer('user_status')->default(0);
            $t->string('display_name', 250)->default('');
        });

        $schema->create($prefix.'usermeta', function (Blueprint $t): void {
            $t->bigIncrements('umeta_id');
            $t->unsignedBigInteger('user_id')->default(0);
            $t->string('meta_key', 255)->nullable();
            $t->longText('meta_value')->nullable();
        });

        $schema->create($prefix.'options', function (Blueprint $t): void {
            $t->bigIncrements('option_id');
            $t->string('option_name', 191)->default('');
            $t->longText('option_value');
            $t->string('autoload', 20)->default('yes');
        });

        if ($languages === 'wpml') {
            $schema->create($prefix.'icl_translations', function (Blueprint $t): void {
                $t->bigIncrements('translation_id');
                $t->string('element_type', 60)->default('post_post');
                $t->unsignedBigInteger('element_id')->nullable();
                $t->unsignedBigInteger('trid');
                $t->string('language_code', 7);
                $t->string('source_language_code', 7)->nullable();
            });
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function insert(Connection $db, string $table, array $rows): void
    {
        foreach (array_chunk($rows, 25) as $chunk) {
            $db->table($table)->insert($chunk);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function users(): array
    {
        $user = fn (int $id, string $login, string $display, string $url = ''): array => [
            'ID' => $id,
            'user_login' => $login,
            'user_pass' => '$P$Bfakehashfakehashfakehash0',
            'user_nicename' => $login,
            'user_email' => $login.'@example.test',
            'user_url' => $url,
            'user_registered' => '2023-01-10 09:00:00',
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $display,
        ];

        return [
            $user(self::USER_ALICE, 'alice', 'Alice Writer', 'https://alice.example.test'),
            $user(self::USER_BORIS, 'boris', 'Борис Автор'),
            $user(self::USER_CAROL, 'carol', 'Carol Reader'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function userMeta(string $prefix): array
    {
        $rows = [];
        foreach ([
            [self::USER_ALICE, 'first_name', 'Alice'],
            [self::USER_ALICE, 'last_name', 'Writer'],
            [self::USER_ALICE, 'description', 'Writes about Laravel.'],
            [self::USER_ALICE, $prefix.'capabilities', serialize(['author' => true])],
            [self::USER_BORIS, 'description', 'Пишет о кухне.'],
            [self::USER_BORIS, $prefix.'capabilities', serialize(['author' => true])],
            [self::USER_CAROL, $prefix.'capabilities', serialize(['subscriber' => true])],
        ] as [$user, $key, $value]) {
            $rows[] = ['user_id' => $user, 'meta_key' => $key, 'meta_value' => $value];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function options(string $languages): array
    {
        $plugins = ['wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php', 'advanced-custom-fields-pro/acf.php'];
        if ($languages === 'wpml') {
            $plugins[] = 'sitepress-multilingual-cms/sitepress.php';
        } elseif ($languages === 'polylang') {
            $plugins[] = 'polylang/polylang.php';
        }

        $options = [
            'siteurl' => self::OLD_URL,
            'home' => self::OLD_URL,
            'blogname' => 'Synthetic Blog',
            'blogdescription' => 'Just an invented site',
            'permalink_structure' => '/%year%/%monthnum%/%postname%/',
            'category_base' => '',
            'tag_base' => '',
            'WPLANG' => $languages === 'none' ? 'ru_RU' : '',
            'active_plugins' => serialize($plugins),
            'wpseo_titles' => serialize([
                'separator' => 'sc-dash',
                'title-home-wpseo' => '%%sitename%% %%page%% %%sep%% %%sitedesc%%',
                'title-post' => '%%title%% %%sep%% %%sitename%%',
            ]),
            'wpseo_taxonomy_meta' => serialize(['category' => [
                self::TERM_NEWS => [
                    'wpseo_title' => 'All the news %%sep%% %%sitename%%',
                    'wpseo_desc' => 'News of the synthetic blog.',
                ],
            ]]),
        ];

        if ($languages === 'wpml') {
            $options['icl_sitepress_settings'] = serialize([
                'default_language' => 'en',
                'language_negotiation_type' => 1,
                'active_languages' => ['en', 'ru'],
            ]);
        } elseif ($languages === 'polylang') {
            $options['polylang'] = serialize(['default_lang' => 'en', 'force_lang' => 1]);
        }

        $rows = [];
        foreach ($options as $name => $value) {
            $rows[] = ['option_name' => $name, 'option_value' => $value, 'autoload' => 'yes'];
        }

        return $rows;
    }

    /**
     * The terms, their taxonomies (count not set yet) and, for Polylang, the
     * relations of terms to their languages and translations.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private static function terms(string $languages): array
    {
        $list = [
            // term_id => [name, slug, taxonomy, parent term_id, description]
            self::TERM_UNCATEGORIZED => ['Uncategorized', 'uncategorized', 'category', 0, ''],
            self::TERM_NEWS => ['News', 'news', 'category', 0, ''],
            self::TERM_LOCAL => ['Local', 'local', 'category', self::TERM_NEWS, ''],
            self::TERM_LARAVEL => ['Laravel', 'laravel', 'post_tag', 0, ''],
            self::TERM_PHP => ['PHP', 'php', 'post_tag', 0, ''],
            self::TERM_ITALIAN => ['Italian', 'italian', 'cuisine', 0, ''],
            self::TERM_RUSSIAN => ['Russian', 'russian', 'cuisine', 0, ''],
            self::TERM_MAIN_MENU => ['Main menu', 'main-menu', 'nav_menu', 0, ''],
        ];

        if ($languages !== 'none') {
            $list[self::TERM_NOVOSTI] = ['Новости', 'novosti', 'category', 0, ''];
        }

        $relations = [];

        if ($languages === 'polylang') {
            $list[self::TERM_LANG_EN] = ['English', 'en', 'language', 0, serialize(['locale' => 'en_US', 'rtl' => 0, 'flag_code' => 'us'])];
            $list[self::TERM_LANG_RU] = ['Русский', 'ru', 'language', 0, serialize(['locale' => 'ru_RU', 'rtl' => 0, 'flag_code' => 'ru'])];
            $list[12] = ['pll_en', 'pll_en', 'term_language', 0, ''];
            $list[13] = ['pll_ru', 'pll_ru', 'term_language', 0, ''];
            $list[14] = ['pll_6a1b2c3d4e5f6', 'pll_6a1b2c3d4e5f6', 'term_translations', 0, serialize(['en' => self::TERM_NEWS, 'ru' => self::TERM_NOVOSTI])];

            foreach ([
                self::TERM_UNCATEGORIZED, self::TERM_NEWS, self::TERM_LOCAL, self::TERM_LARAVEL,
                self::TERM_PHP, self::TERM_ITALIAN, self::TERM_RUSSIAN,
            ] as $term) {
                $relations[] = self::relation($term, 12 + self::TT_OFFSET);
            }
            $relations[] = self::relation(self::TERM_NOVOSTI, 13 + self::TT_OFFSET);
            $relations[] = self::relation(self::TERM_NEWS, 14 + self::TT_OFFSET);
            $relations[] = self::relation(self::TERM_NOVOSTI, 14 + self::TT_OFFSET);

            $next = 20;
            foreach (self::translationGroups() as $group) {
                $slug = 'pll_'.substr(md5((string) reset($group)), 0, 13);
                $list[$next] = [$slug, $slug, 'post_translations', 0, serialize($group)];
                $next++;
            }
        }

        $terms = [];
        $taxonomies = [];
        foreach ($list as $id => [$name, $slug, $taxonomy, $parent, $description]) {
            $terms[] = ['term_id' => $id, 'name' => $name, 'slug' => $slug, 'term_group' => $id === self::TERM_LANG_RU ? 1 : 0];
            $taxonomies[] = [
                'term_taxonomy_id' => $id + self::TT_OFFSET,
                'term_id' => $id,
                'taxonomy' => $taxonomy,
                'description' => $description,
                'parent' => $parent,
                'count' => 0,
            ];
        }

        return [$terms, $taxonomies, $relations];
    }

    /**
     * Translation groups of posts, pages and recipes: language => post id.
     *
     * @return list<array<string, int>>
     */
    private static function translationGroups(): array
    {
        return [
            ['en' => self::HELLO, 'ru' => self::HELLO_RU],
            ['en' => self::GUTENBERG],
            ['en' => self::DRAFT_IDEA],
            ['en' => self::HELLO_AGAIN],
            ['en' => self::TRASHED],
            ['en' => self::ENCODING],
            ['en' => self::SCHEDULED],
            ['en' => self::PRIVATE_NOTE],
            ['en' => self::ORPHAN],
            ['en' => self::ABOUT, 'ru' => self::ABOUT_RU],
            ['en' => self::TEAM],
            ['en' => self::HISTORY],
            ['en' => self::LOST_CHILD],
            ['en' => self::CONTACTS],
            ['en' => self::BORSCHT, 'ru' => self::BORSCHT_RU],
            ['en' => self::PIZZA],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function relation(int $object, int $taxonomyId): array
    {
        return ['object_id' => $object, 'term_taxonomy_id' => $taxonomyId, 'term_order' => 0];
    }

    /**
     * What posts are filed under, and for Polylang what language and
     * translation group they belong to.
     *
     * @return list<array<string, mixed>>
     */
    private static function postRelations(string $languages): array
    {
        $tt = fn (int $term): int => $term + self::TT_OFFSET;
        $rows = [];

        foreach ([
            self::HELLO => [self::TERM_NEWS, self::TERM_LARAVEL, self::TERM_PHP],
            self::HELLO_RU => [self::TERM_NOVOSTI],
            self::GUTENBERG => [self::TERM_LOCAL],
            self::DRAFT_IDEA => [self::TERM_UNCATEGORIZED],
            self::HELLO_AGAIN => [self::TERM_UNCATEGORIZED],
            self::TRASHED => [self::TERM_UNCATEGORIZED],
            self::AUTO_DRAFT => [self::TERM_UNCATEGORIZED],
            self::REVISION => [self::TERM_UNCATEGORIZED],
            self::ENCODING => [self::TERM_UNCATEGORIZED],
            self::SCHEDULED => [self::TERM_UNCATEGORIZED],
            self::PRIVATE_NOTE => [self::TERM_UNCATEGORIZED],
            self::ORPHAN => [self::TERM_UNCATEGORIZED],
            self::BORSCHT => [self::TERM_RUSSIAN],
            self::BORSCHT_RU => [self::TERM_RUSSIAN],
            self::PIZZA => [self::TERM_ITALIAN],
        ] as $post => $terms) {
            foreach ($terms as $term) {
                $rows[] = self::relation($post, $tt($term));
            }
        }

        if ($languages === 'polylang') {
            $next = 20;
            foreach (self::translationGroups() as $group) {
                foreach ($group as $language => $post) {
                    $rows[] = self::relation($post, $tt($language === 'en' ? self::TERM_LANG_EN : self::TERM_LANG_RU));
                    $rows[] = self::relation($post, $tt($next));
                }
                $next++;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function termMeta(): array
    {
        return [
            ['term_id' => self::TERM_LARAVEL, 'meta_key' => 'rank_math_title', 'meta_value' => 'Laravel posts %sep% %sitename%'],
            ['term_id' => self::TERM_LARAVEL, 'meta_key' => 'rank_math_description', 'meta_value' => 'Everything about Laravel.'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function wpmlTranslations(): array
    {
        $rows = [];
        $trid = 1000;
        $add = function (string $type, array $group) use (&$rows, &$trid): void {
            $trid++;
            foreach ($group as $language => $element) {
                $rows[] = [
                    'element_type' => $type,
                    'element_id' => $element,
                    'trid' => $trid,
                    'language_code' => $language,
                    'source_language_code' => $language === 'en' ? null : 'en',
                ];
            }
        };

        $types = [
            self::HELLO => 'post_post', self::GUTENBERG => 'post_post', self::DRAFT_IDEA => 'post_post',
            self::HELLO_AGAIN => 'post_post', self::TRASHED => 'post_post', self::ENCODING => 'post_post',
            self::SCHEDULED => 'post_post', self::PRIVATE_NOTE => 'post_post', self::ORPHAN => 'post_post',
            self::ABOUT => 'post_page', self::TEAM => 'post_page', self::HISTORY => 'post_page',
            self::LOST_CHILD => 'post_page', self::CONTACTS => 'post_page',
            self::BORSCHT => 'post_recipe', self::PIZZA => 'post_recipe',
        ];
        foreach (self::translationGroups() as $group) {
            $add($types[$group['en']], $group);
        }

        foreach ([self::PHOTO, self::BORSCHT_PHOTO, self::MISSING_PHOTO, self::MANUAL] as $attachment) {
            $add('post_attachment', ['en' => $attachment]);
        }

        // WPML identifies terms by term_taxonomy_id.
        $tt = fn (int $term): int => $term + self::TT_OFFSET;
        $add('tax_category', ['en' => $tt(self::TERM_UNCATEGORIZED)]);
        $add('tax_category', ['en' => $tt(self::TERM_NEWS), 'ru' => $tt(self::TERM_NOVOSTI)]);
        $add('tax_category', ['en' => $tt(self::TERM_LOCAL)]);
        $add('tax_post_tag', ['en' => $tt(self::TERM_LARAVEL)]);
        $add('tax_post_tag', ['en' => $tt(self::TERM_PHP)]);
        $add('tax_cuisine', ['en' => $tt(self::TERM_ITALIAN)]);
        $add('tax_cuisine', ['en' => $tt(self::TERM_RUSSIAN)]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function post(int $id, string $type, string $status, string $title, array $values = []): array
    {
        $date = $values['post_date'] ?? '2024-05-10 10:00:00';
        $modified = $values['post_modified'] ?? $date;

        return array_merge([
            'ID' => $id,
            'post_author' => self::USER_ALICE,
            'post_date' => $date,
            'post_date_gmt' => self::gmt($date),
            'post_content' => '',
            'post_title' => $title,
            'post_excerpt' => '',
            'post_status' => $status,
            'comment_status' => 'open',
            'ping_status' => 'open',
            'post_password' => '',
            'post_name' => '',
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $modified,
            'post_modified_gmt' => self::gmt($modified),
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => self::OLD_URL.'/?p='.$id,
            'menu_order' => 0,
            'post_type' => $type,
            'post_mime_type' => '',
            'comment_count' => 0,
        ], $values);
    }

    /** The site is three hours ahead of UTC. */
    private static function gmt(string $local): string
    {
        return (new DateTimeImmutable($local, new DateTimeZone('UTC')))->modify('-3 hours')->format('Y-m-d H:i:s');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function posts(): array
    {
        $posts = [
            self::post(self::HELLO, 'post', 'publish', 'Hello World', [
                'post_name' => 'hello-world',
                'post_content' => self::HELLO_CONTENT,
                'post_excerpt' => 'A short hello.',
                'post_modified' => '2024-05-11 12:30:00',
            ]),
            self::post(self::HELLO_RU, 'post', 'publish', 'Привет, мир', [
                'post_author' => self::USER_ALICE,
                'post_name' => self::HELLO_RU_SLUG_ENCODED,
                'post_content' => 'Первый абзац.',
                'post_date' => '2024-05-10 10:05:00',
            ]),
            self::post(self::GUTENBERG, 'post', 'publish', 'Gutenberg post', [
                'post_author' => self::USER_BORIS,
                'post_name' => 'gutenberg-post',
                'post_content' => self::GUTENBERG_CONTENT,
                'post_date' => '2024-05-20 09:00:00',
            ]),
            self::post(self::DRAFT_IDEA, 'post', 'draft', 'Draft idea', ['post_date' => '2024-05-21 09:00:00']),
            self::post(self::HELLO_AGAIN, 'post', 'draft', 'Hello again', [
                'post_name' => 'hello-world',
                'post_date' => '2024-05-22 09:00:00',
            ]),
            self::post(self::TRASHED, 'post', 'trash', 'Thrown away', [
                'post_name' => 'thrown-away__trashed',
                'post_date' => '2024-05-23 09:00:00',
            ]),
            self::post(self::AUTO_DRAFT, 'post', 'auto-draft', 'Auto Draft', ['post_date' => '2024-05-24 09:00:00']),
            self::post(self::REVISION, 'revision', 'inherit', 'Hello World', [
                'post_name' => self::HELLO.'-revision-v1',
                'post_parent' => self::HELLO,
                'post_content' => 'First paragraph.',
                'post_date' => '2024-05-10 09:55:00',
            ]),
            self::post(self::ENCODING, 'post', 'publish', self::encodingTitle(), [
                'post_name' => 'encoding-mess',
                'post_content' => self::encodingContent(),
                'post_date' => '2024-05-25 09:00:00',
            ]),
            self::post(self::SCHEDULED, 'post', 'future', 'Scheduled', [
                'post_name' => 'scheduled',
                'post_date' => '2030-01-15 10:00:00',
            ]),
            self::post(self::PRIVATE_NOTE, 'post', 'private', 'Private note', [
                'post_name' => 'private-note',
                'post_date' => '2024-05-26 09:00:00',
            ]),
            self::post(self::ORPHAN, 'post', 'publish', 'Orphan author', [
                'post_author' => self::USER_MISSING,
                'post_name' => 'orphan-author',
                'post_date' => '2024-05-27 09:00:00',
            ]),

            self::post(self::ABOUT, 'page', 'publish', 'About', [
                'post_name' => 'about',
                'post_content' => 'Who we are.',
                'menu_order' => 1,
                'post_date' => '2024-04-01 10:00:00',
            ]),
            self::post(self::ABOUT_RU, 'page', 'publish', 'О нас', [
                'post_name' => 'o-nas',
                'post_content' => 'Кто мы.',
                'post_date' => '2024-04-01 10:05:00',
            ]),
            self::post(self::TEAM, 'page', 'publish', 'Team', [
                'post_name' => 'team',
                'post_parent' => self::ABOUT,
                'post_date' => '2024-04-02 10:00:00',
            ]),
            self::post(self::HISTORY, 'page', 'publish', 'History', [
                'post_name' => 'history',
                'post_parent' => self::TEAM,
                'post_date' => '2024-04-03 10:00:00',
            ]),
            self::post(self::LOST_CHILD, 'page', 'publish', 'Lost child', [
                'post_name' => 'lost-child',
                'post_parent' => self::NO_SUCH_POST,
                'post_date' => '2024-04-04 10:00:00',
            ]),
            self::post(self::CONTACTS, 'page', 'draft', 'Contacts', ['post_date' => '2024-04-05 10:00:00']),

            self::post(self::ACF_GROUP, 'acf-field-group', 'publish', 'Recipe fields', [
                'post_name' => 'group_recipe',
                'post_content' => serialize([
                    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'recipe']]],
                    'position' => 'normal',
                ]),
                'post_date' => '2024-03-01 10:00:00',
            ]),
        ];

        foreach ([
            // id => [label, name, settings, parent, order]
            61 => ['Cooking time', 'cooking_time', ['type' => 'number'], self::ACF_GROUP, 0],
            62 => ['Difficulty', 'difficulty', ['type' => 'select', 'choices' => ['easy' => 'Easy', 'hard' => 'Hard'], 'multiple' => 0], self::ACF_GROUP, 1],
            63 => ['Ingredients', 'ingredients', ['type' => 'textarea'], self::ACF_GROUP, 2],
            64 => ['Spicy', 'spicy', ['type' => 'true_false'], self::ACF_GROUP, 3],
            65 => ['Served on', 'served_on', ['type' => 'date_picker'], self::ACF_GROUP, 4],
            66 => ['Photo', 'photo', ['type' => 'image'], self::ACF_GROUP, 5],
            67 => ['Gallery', 'gallery', ['type' => 'gallery'], self::ACF_GROUP, 6],
            68 => ['Labels', 'labels', ['type' => 'checkbox', 'choices' => ['vegan' => 'Vegan', 'quick' => 'Quick']], self::ACF_GROUP, 7],
            69 => ['Steps', 'steps', ['type' => 'repeater'], self::ACF_GROUP, 8],
            70 => ['Step text', 'step_text', ['type' => 'text'], self::ACF_STEPS, 0],
            71 => ['Notes', 'notes', ['type' => 'wysiwyg'], self::ACF_GROUP, 9],
        ] as $id => [$label, $name, $settings, $parent, $order]) {
            $posts[] = self::post($id, 'acf-field', 'publish', $label, [
                'post_excerpt' => $name,
                'post_name' => self::ACF_KEYS[$name],
                'post_content' => serialize($settings),
                'post_parent' => $parent,
                'menu_order' => $order,
                'post_date' => '2024-03-01 10:00:00',
            ]);
        }

        $posts[] = self::post(self::BORSCHT, 'recipe', 'publish', 'Borscht', [
            'post_author' => self::USER_BORIS,
            'post_name' => 'borscht',
            'post_content' => 'A classic beetroot soup.',
            'post_date' => '2024-06-01 10:00:00',
        ]);
        $posts[] = self::post(self::BORSCHT_RU, 'recipe', 'publish', 'Борщ', [
            'post_author' => self::USER_BORIS,
            'post_name' => 'borshch',
            'post_content' => 'Классический суп из свёклы.',
            'post_date' => '2024-06-01 10:05:00',
        ]);
        $posts[] = self::post(self::PIZZA, 'recipe', 'publish', 'Pizza', [
            'post_name' => 'pizza',
            'post_content' => 'Dough, tomatoes, cheese.',
            'post_date' => '2024-06-03 10:00:00',
        ]);

        foreach ([
            // id => [title, file, mime, date, parent, content]
            self::PHOTO => ['Photo', '2024/05/photo.jpg', 'image/jpeg', '2024-05-10 09:00:00', self::HELLO, ''],
            self::BORSCHT_PHOTO => ['Borscht photo', '2024/06/borscht.png', 'image/png', '2024-06-01 09:00:00', self::BORSCHT, ''],
            self::MISSING_PHOTO => ['Missing photo', '2024/07/missing.jpg', 'image/jpeg', '2024-07-03 09:00:00', self::PIZZA, ''],
            self::MANUAL => ['Manual', 'docs/manual.pdf', 'application/pdf', '2024-07-04 09:00:00', 0, 'The printed manual.'],
        ] as $id => [$title, $file, $mime, $date, $parent, $content]) {
            $posts[] = self::post($id, 'attachment', 'inherit', $title, [
                'post_name' => strtolower(str_replace(' ', '-', $title)),
                'post_content' => $content,
                'post_parent' => $parent,
                'post_date' => $date,
                'guid' => self::OLD_URL.'/wp-content/uploads/'.$file,
                'post_mime_type' => $mime,
            ]);
        }

        return $posts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function postMeta(): array
    {
        $rows = [];
        $add = function (int $post, string $key, string|int $value) use (&$rows): void {
            $rows[] = ['post_id' => $post, 'meta_key' => $key, 'meta_value' => (string) $value];
        };

        // Posts. Keys without an underscore are only those of the SEO plugins.
        $add(self::HELLO, '_thumbnail_id', self::PHOTO);
        $add(self::HELLO, '_edit_lock', '1715330000:1');
        $add(self::HELLO, '_edit_last', '1');
        $add(self::HELLO, '_yoast_wpseo_title', '%%title%% %%sep%% %%sitename%%');
        $add(self::HELLO, '_yoast_wpseo_metadesc', 'Our first post.');
        $add(self::HELLO, '_yoast_wpseo_focuskw', 'hello');
        $add(self::HELLO, '_yoast_wpseo_canonical', self::OLD_URL.'/2024/05/hello-world/');
        $add(self::HELLO, '_yoast_wpseo_opengraph-image', self::OLD_URL.'/wp-content/uploads/2024/05/photo.jpg');

        $add(self::GUTENBERG, 'rank_math_title', '%title% %sep% %sitename%');
        $add(self::GUTENBERG, 'rank_math_description', 'Blocks everywhere.');
        $add(self::GUTENBERG, 'rank_math_focus_keyword', 'blocks,gutenberg');
        $add(self::GUTENBERG, 'rank_math_robots', serialize(['noindex', 'nofollow']));
        $add(self::GUTENBERG, 'rank_math_facebook_title', 'Gutenberg on Facebook');

        $add(self::ORPHAN, '_thumbnail_id', self::NO_SUCH_POST);

        // Pages.
        $add(self::ABOUT, '_yoast_wpseo_title', 'About us');
        $add(self::ABOUT, '_yoast_wpseo_metadesc', 'Who we are.');
        $add(self::ABOUT, '_yoast_wpseo_meta-robots-noindex', '1');
        $add(self::ABOUT, '_wp_page_template', 'default');

        // Attachments.
        $add(self::PHOTO, '_wp_attached_file', '2024/05/photo.jpg');
        $add(self::PHOTO, '_wp_attachment_image_alt', 'A photo of the street');
        $add(self::PHOTO, '_wp_attachment_metadata', self::brokenAttachmentMetadata());
        $add(self::BORSCHT_PHOTO, '_wp_attached_file', '2024/06/borscht.png');
        $add(self::BORSCHT_PHOTO, '_wp_attachment_image_alt', 'A plate of borscht');
        $add(self::BORSCHT_PHOTO, '_wp_attachment_metadata', serialize([
            'width' => 800,
            'height' => 600,
            'file' => '2024/06/borscht.png',
            'sizes' => [],
        ]));
        $add(self::MISSING_PHOTO, '_wp_attached_file', '2024/07/missing.jpg');
        $add(self::MANUAL, '_wp_attached_file', 'docs/manual.pdf');

        // Recipes: an ACF value is saved under its name, the field key under _name.
        $acf = function (int $post, array $values) use ($add): void {
            foreach ($values as $name => [$value, $key]) {
                $add($post, $name, $value);
                $add($post, '_'.$name, $key);
            }
        };
        $k = self::ACF_KEYS;
        $borscht = fn (string $ingredients, string $notes): array => [
            'cooking_time' => ['45', $k['cooking_time']],
            'difficulty' => ['easy', $k['difficulty']],
            'ingredients' => [$ingredients, $k['ingredients']],
            'spicy' => ['1', $k['spicy']],
            'served_on' => ['20240115', $k['served_on']],
            'photo' => ['51', $k['photo']],
            'gallery' => [serialize(['50', '51']), $k['gallery']],
            'labels' => [self::BROKEN_LABELS, $k['labels']],
            'steps' => ['2', $k['steps']],
            'steps_0_step_text' => ['Boil water', $k['step_text']],
            'steps_1_step_text' => ['Add beets', $k['step_text']],
            'notes' => [$notes, $k['notes']],
        ];
        $acf(self::BORSCHT, $borscht("Beetroot\nCabbage", '<p>Serve hot.</p>'));
        $acf(self::BORSCHT_RU, $borscht("Свёкла\nКапуста", '<p>Подавать горячим.</p>'));
        $acf(self::PIZZA, [
            'cooking_time' => ['20', $k['cooking_time']],
            'photo' => ['52', $k['photo']],
            'spicy' => ['0', $k['spicy']],
        ]);

        $add(self::BORSCHT, 'source_url', 'https://cook.example.test/borscht');
        $add(self::BORSCHT, 'rank_math_title', 'Borscht recipe');
        $add(self::BORSCHT, 'rank_math_description', 'Classic borscht.');

        return $rows;
    }
}
