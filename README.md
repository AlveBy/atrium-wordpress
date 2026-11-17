# Atrium WordPress importer

Moves a WordPress site to Laravel and the [Atrium](https://github.com/AlveBy/atrium) admin panel: posts, pages, custom post types with their ACF fields, categories, tags, authors, attachments, Yoast or Rank Math SEO and WPML or Polylang translations, then proves nothing was lost and writes the 301 map from the old addresses.

The WordPress database is only read. Works with MySQL, PostgreSQL and SQLite on the Laravel side.

## Install

```bash
composer require alveby/atrium-wordpress --dev
php artisan vendor:publish --tag=atrium-wordpress-config
```

Add a connection to a copy of the WordPress database in `config/database.php` (the default name is `wordpress`, see `WORDPRESS_DB_CONNECTION`; the table prefix is `WORDPRESS_TABLE_PREFIX`, `wp_` by default) and point `WORDPRESS_UPLOADS_PATH` at a copy of `wp-content/uploads`.

## Move a site

```bash
php artisan atrium:wordpress:analyze       # what the old database holds and where each part goes
php artisan atrium:wordpress:generate      # models and migrations of the modules it needs
php artisan migrate
php artisan atrium:wordpress:migrate:data  # posts, pages, terms, authors, files, SEO
php artisan atrium:wordpress:parity        # before against after, record by record
php artisan atrium:wordpress:redirects --format=nginx --output=redirects.conf
```

`atrium:wordpress:migrate` runs generate, migrate, the data and parity in one go. `atrium:wordpress:sync --prune --audit` catches up with the edits made in WordPress since the last run.

Posts go to News (`alveby/atrium-news`) and pages to Pages (`alveby/atrium-pages`) when those modules are installed and the type has no custom fields; everything else gets a generated module. `post_types` and `taxonomies` in `config/atrium-wordpress.php` change that.

The step-by-step guide, what moves, how messy data is handled and what does not move yet: [docs/wordpress.md](../../docs/wordpress.md).

## License

MIT
