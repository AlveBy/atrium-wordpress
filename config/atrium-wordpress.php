<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | The old site's database
    |--------------------------------------------------------------------------
    |
    | A connection name from config/database.php pointing at a copy of the
    | WordPress database, and the prefix of its tables (wp_ unless the site
    | was installed with another one). The importer only reads from it.
    |
    */
    'connection' => env('WORDPRESS_DB_CONNECTION', 'wordpress'),
    'table_prefix' => env('WORDPRESS_TABLE_PREFIX', 'wp_'),

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    |
    | uploads_path: a copy of the old site's wp-content/uploads folder.
    | uploads_target: where the files go, under public/. The default keeps
    | their addresses: /wp-content/uploads/2024/05/photo.jpg opens on the new
    | site as it did on the old one, so links and image search keep working.
    |
    */
    'uploads_path' => env('WORDPRESS_UPLOADS_PATH'),
    'uploads_target' => 'wp-content/uploads',

    /*
    |--------------------------------------------------------------------------
    | The Atrium site the content goes to
    |--------------------------------------------------------------------------
    |
    | A site code; null is the default site.
    |
    */
    'site' => null,

    /*
    |--------------------------------------------------------------------------
    | The default language
    |--------------------------------------------------------------------------
    |
    | Texts are filed under language codes. By default the old site's own:
    | WPML's or Polylang's default language, or the one of WPLANG. Set a
    | code here to file a site without a language plugin under another one.
    |
    */
    'language' => null,

    /*
    |--------------------------------------------------------------------------
    | Where each post type goes
    |--------------------------------------------------------------------------
    |
    | By default posts become news (alveby/atrium-news) and pages become pages
    | (alveby/atrium-pages), when those modules are installed and the type
    | has no custom fields they could not hold. Every other type gets a
    | module of its own, generated like Bitrix iblocks: a migration and a
    | model with its admin form.
    |
    |   'post' => 'news',            // or 'generate'
    |   'page' => 'pages',           // or 'generate'
    |   'product' => 'skip',
    |   'recipe' => ['table' => 'recipes', 'model' => 'Recipe', 'prefix' => 'recipes'],
    |
    | The same for taxonomies: 'taxonomies' => ['cuisine' => ['table' => 'cuisines']].
    |
    */
    'post_types' => [],
    'taxonomies' => [],

    /*
    |--------------------------------------------------------------------------
    | Custom fields left behind
    |--------------------------------------------------------------------------
    |
    | Meta keys (shell patterns) that are not content: plugin bookkeeping a
    | site gathers over the years. Keys starting with an underscore are
    | never content and need not be listed.
    |
    */
    'skip_meta' => [
        'rank_math_*',
        'wpml_*',
        'pll_*',
        'classic-editor-remember',
        'inline_featured_image',
        'ampforwp_*',
        'site-sidebar-layout',
        'site-content-layout',
        'theme-transparent-header-meta',
        'footnotes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the generated code goes
    |--------------------------------------------------------------------------
    */
    'model_namespace' => 'App\\Models',
    'model_path' => null, // null = the folder PSR-4 gives the namespace
    'migration_path' => null, // null = database_path('migrations')

    'chunk_size' => 200,
];
