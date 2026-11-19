<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Import;

use Atrium\WordPress\Plan\Field;
use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Plan\Taxonomy;
use Atrium\WordPress\Plan\Type;
use Atrium\WordPress\Support\Serialized;
use Atrium\WordPress\Support\Text;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What every WordPress post, term, author and file must become in Atrium.
 * The importer writes these rows; the parity audit compares them with what
 * is in the database, so the two can never disagree about what "moved
 * completely" means.
 *
 * A record is one translation group: the post in the default language and
 * its translations, with a text per language.
 */
final class Records
{
    public readonly Content $content;

    public readonly Permalinks $permalinks;

    private Seo $seo;

    /** @var array<int,object> term_taxonomy_id => term */
    private array $terms = [];

    /** @var array<int,int> term_taxonomy_id => wordpress_id of its record */
    private array $termRecord = [];

    /** @var array<string,array<int,array<string,mixed>>> taxonomy => records */
    private array $termRecords = [];

    /** @var array<int,array<string,mixed>>|null */
    private ?array $attachments = null;

    /** @var array<string,array<string,mixed>> post type => index */
    private array $indexes = [];

    /** @var array<string,string> problems the old site already had, by key so each is told once */
    private array $notes = [];

    public function __construct(public readonly Plan $plan)
    {
        $this->content = new Content($plan->home(), (string) $plan->source->option('siteurl'));
        $this->seo = new Seo($plan->source, $this->content);
        $this->permalinks = new Permalinks($plan);

        foreach ($plan->source->terms() as $term) {
            $this->terms[$term->term_taxonomy_id] = $term;
        }
    }

    /**
     * Damage found in the old site: missing files, parents and authors,
     * serialized values that had to be repaired. It moved as well as it could.
     *
     * @return array<int,string>
     */
    public function notes(): array
    {
        return array_values($this->notes);
    }

    /**
     * The records of a post type, a chunk of translation groups at a time.
     *
     * @return iterable<int,array<int,array<string,mixed>>>
     */
    public function records(Type $type, ?int $chunk = null): iterable
    {
        $index = $this->index($type);
        $chunk ??= max(1, (int) config('atrium-wordpress.chunk_size', 200));

        foreach (array_chunk($index['groups'], $chunk, true) as $groups) {
            $ids = array_merge(...array_map(array_values(...), $groups));
            $posts = $this->plan->source->posts($ids);
            $meta = $this->plan->source->meta($ids);
            $links = $this->plan->source->relationships($ids);
            $records = [];

            foreach ($groups as $main => $members) {
                $records[$main] = $this->record($type, $main, $members, $posts, $meta, $links, $index);
            }

            yield $records;
        }
    }

    /**
     * Number of records a post type gives: its translation groups.
     */
    public function count(Type $type): int
    {
        return count($this->index($type)['groups']);
    }

    /**
     * The new path of a record of a post type in a language, from the codes
     * the index gave out, or null when it has no text in that language.
     */
    public function target(Type $type, int $main, string $language): ?string
    {
        $index = $this->index($type);
        $code = $index['codes'][$main] ?? null;

        if (is_array($code)) {
            $code = $code[$language] ?? null;
        } elseif (! isset($index['groups'][$main][$language])) {
            $code = null;
        }

        if ($code === null || $code === '') {
            return null;
        }

        if ($type->hierarchical) {
            $parts = [$code];

            // The index cut every loop of parents, so this walk ends.
            for ($parent = $index['parents'][$main] ?? null; $parent !== null; $parent = $index['parents'][$parent] ?? null) {
                $parentCode = $index['codes'][$parent] ?? '';
                array_unshift($parts, is_array($parentCode) ? ($parentCode[$language] ?? reset($parentCode)) : $parentCode);
            }

            $code = implode('/', $parts);
        }

        return $this->path($type->prefix, $code, $language);
    }

    /**
     * The records of a taxonomy: its terms, translations merged.
     *
     * @return array<int,array<string,mixed>>
     */
    public function terms(Taxonomy $taxonomy): array
    {
        if (isset($this->termRecords[$taxonomy->taxonomy])) {
            return $this->termRecords[$taxonomy->taxonomy];
        }

        $languages = $this->plan->languages;
        $groups = [];
        $byTermId = [];

        foreach ($this->terms as $id => $term) {
            if ($term->taxonomy === $taxonomy->taxonomy) {
                $group = $languages->termGroup($term);
                $language = $languages->ofTerm($term);
                isset($groups[$group][$language]) ? $groups["term:{$id}"][$language] = $id : $groups[$group][$language] = $id;
                $byTermId[$term->term_id] = $id;
            }
        }

        $records = [];
        $used = [];
        $termMeta = $this->plan->source->termMeta();

        foreach ($groups as $members) {
            ksort($members);
            $main = $members[$languages->default()] ?? min($members);

            foreach ($members as $id) {
                $this->termRecord[$id] = $main;
            }

            $term = $this->terms[$main];
            $code = $this->unique(Content::slug((string) $term->slug, (string) $term->name, 'term-'.$main), $used);
            $name = $description = $paths = [];

            foreach ($members as $language => $id) {
                $name[$language] = $this->content->text($this->terms[$id]->name) ?: $code;
                $description[$language] = $this->content->text($this->terms[$id]->description);
                $paths[$language] = $this->permalinks->term($this->terms[$id], $language);
            }

            $records[$main] = [
                'wordpress_id' => $main,
                'terms' => $members,
                'values' => [
                    'name' => $name,
                    'code' => $code,
                    'description' => $this->translations($description),
                    'sort' => 500,
                ],
                'parent_term' => $term->parent,
                'seo' => $this->seo->forTerm($taxonomy->taxonomy, (int) $term->term_id, $termMeta[$term->term_id] ?? [], $name[$languages->default()] ?? reset($name), $description[$languages->default()] ?? '', $this->plan->home()),
                'paths' => $paths,
                'targets' => array_map(fn (string $language): string => $this->path($taxonomy->prefix, $code, $language), array_combine(array_keys($members), array_keys($members))),
            ];
        }

        foreach ($records as $main => $record) {
            $parent = $byTermId[$record['parent_term']] ?? null;
            $records[$main]['parent'] = $taxonomy->hierarchical && $parent !== null && $this->termRecord[$parent] !== $main ? $this->termRecord[$parent] : null;
            unset($records[$main]['parent_term']);
        }

        ksort($records);

        return $this->termRecords[$taxonomy->taxonomy] = $records;
    }

    /**
     * The record a term was merged into, for the links of posts to it.
     */
    public function termRecord(int $termTaxonomyId): ?int
    {
        if (! isset($this->terms[$termTaxonomyId])) {
            return null;
        }

        if (! isset($this->termRecord[$termTaxonomyId]) && ($taxonomy = $this->plan->taxonomy((string) $this->terms[$termTaxonomyId]->taxonomy)) !== null) {
            $this->terms($taxonomy);
        }

        return $this->termRecord[$termTaxonomyId] ?? null;
    }

    /**
     * The users who wrote the posts that move. Never their passwords: they
     * become authors to credit, not people who sign in.
     *
     * @return array<int,array<string,mixed>>
     */
    public function authors(): array
    {
        $ids = [];

        foreach ($this->plan->moving() as $type) {
            foreach ($this->index($type)['authors'] as $id) {
                $ids[$id] = $id;
            }
        }

        $records = [];

        foreach ($this->plan->source->users(array_values($ids)) as $id => $user) {
            $name = $this->content->text($user->display_name ?? '') ?: trim($this->content->text($user->first_name ?? '').' '.$this->content->text($user->last_name ?? '')) ?: $user->user_login;

            $records[$id] = [
                'wordpress_id' => $id,
                'values' => [
                    'name' => $name,
                    'code' => $user->user_nicename ?: $user->user_login,
                    'email' => $user->user_email ?: null,
                    'url' => $user->user_url ?: null,
                    'description' => $this->content->text($user->description ?? '') ?: null,
                ],
            ];
        }

        foreach (array_diff_key($ids, $records) as $id) {
            $this->note("author:{$id}", "user {$id} wrote posts but is not in wp_users: they move without an author");
        }

        ksort($records);

        return $records;
    }

    /**
     * Every file of the media library: the attachment posts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function attachments(): array
    {
        if ($this->attachments !== null) {
            return $this->attachments;
        }

        $source = $this->plan->source;
        $ids = $source->postIds('attachment', ['inherit', 'publish', 'private']);
        $meta = $source->meta($ids);
        $this->attachments = [];

        foreach ($source->posts($ids) as $id => $post) {
            $file = ltrim((string) ($meta[$id]['_wp_attached_file'] ?? ''), '/');

            if ($file === '') {
                // An attachment without a file of its own: only its old address is known.
                $file = Str::after((string) $post->guid, '/wp-content/uploads/');
            }

            $raw = $meta[$id]['_wp_attachment_metadata'] ?? null;

            if (Serialized::wasRepaired($raw)) {
                $this->note("attachment-meta:{$id}", "the sizes of attachment {$id} were saved with wrong string lengths (a search and replace on the database); they were read after a repair");
            }

            $sizes = [];

            foreach ((array) (Serialized::toArray($raw)['sizes'] ?? []) as $size) {
                if (is_array($size) && is_string($size['file'] ?? null) && $size['file'] !== '') {
                    $sizes[] = ltrim((dirname($file) === '.' ? '' : dirname($file).'/').$size['file'], '/');
                }
            }

            $this->attachments[$id] = [
                'id' => $id,
                'file' => $file,
                'url' => Content::uploadsBase().'/'.$file,
                'title' => $this->content->text($post->post_title) ?: basename($file),
                'alt' => $this->content->text($meta[$id]['_wp_attachment_image_alt'] ?? ''),
                'description' => $this->content->text($post->post_content),
                'mime' => $post->post_mime_type,
                'parent' => (int) $post->post_parent,
                'sizes' => array_values(array_unique($sizes)),
                'date' => $this->moment($post),
            ];
        }

        ksort($this->attachments);

        return $this->attachments;
    }

    /**
     * The new address of an attachment, or null for one that is not there.
     */
    public function attachmentUrl(mixed $id, string $context): ?string
    {
        if (! is_numeric($id) || (int) $id === 0) {
            return null;
        }

        $attachment = $this->attachments()[(int) $id] ?? null;

        if ($attachment === null) {
            $this->note("attachment:{$id}:{$context}", "{$context} points at attachment {$id}, which is not in the database");

            return null;
        }

        // A link to a file that is not there is worse than none: the page would show a broken picture.
        if (! is_file(config('atrium-wordpress.uploads_path').'/'.$attachment['file'])) {
            $this->note("attachment-file:{$id}:{$context}", "{$context} points at attachment {$id}, whose file {$attachment['file']} is missing in the uploads folder");

            return null;
        }

        return $attachment['url'];
    }

    /**
     * The pictures of a [gallery]: the attachments it names, or the ones
     * attached to the post that has it.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array{url:string,alt:string}>
     */
    private function gallery(array $ids, int $post): array
    {
        $attachments = $this->attachments();

        if ($ids === []) {
            $ids = array_keys(array_filter($attachments, static fn (array $attachment): bool => $attachment['parent'] === $post));
        }

        $images = [];

        foreach ($ids as $id) {
            // A gallery shows pictures: a PDF named in it would be a broken <img>.
            if (($url = $this->attachmentUrl($id, "the gallery of post {$post}")) !== null && str_starts_with((string) $attachments[$id]['mime'], 'image/')) {
                $images[] = ['url' => $url, 'alt' => $attachments[$id]['alt']];
            }
        }

        return $images;
    }

    /**
     * The address of a record on the new site: /{prefix}/{code}, behind
     * /{language} for every language but the default one.
     */
    public function path(string $prefix, string $code, string $language): string
    {
        $path = '/'.ltrim(trim($prefix, '/').'/'.$code, '/');

        return $language === $this->plan->languages->default() ? $path : '/'.$language.$path;
    }

    /**
     * @param  array<string,int>  $members  language => post ID
     * @param  array<int,object>  $posts
     * @param  array<int,array<string,string>>  $meta
     * @param  array<int,array<int,int>>  $links
     * @param  array<string,mixed>  $index
     * @return array<string,mixed>
     */
    private function record(Type $type, int $main, array $members, array $posts, array $meta, array $links, array $index): array
    {
        $post = $posts[$main];
        $default = $this->plan->languages->default();
        $name = $excerpt = $text = $paths = $targets = $short = [];
        $files = [];

        foreach ($members as $language => $id) {
            $row = $posts[$id];
            $name[$language] = $this->content->text($row->post_title) ?: 'Untitled '.$id;
            $excerpt[$language] = $this->content->text($row->post_excerpt);
            $text[$language] = $this->content->html($row->post_content, fn (array $ids): array => $this->gallery($ids, $id));
            $files = [...$files, ...Content::referencedUploads($text[$language])];

            if (($old = $this->permalinks->post($row, $language)) !== null) {
                $paths[$language] = $old;
                $short[$language] = Permalinks::shortLink($row);
                $targets[$language] = $this->target($type, $main, $language);
            }
        }

        $thumbnail = null;

        foreach ([$main, ...$members] as $id) {
            if (($meta[$id]['_thumbnail_id'] ?? '') !== '') {
                $thumbnail = $this->attachmentUrl($meta[$id]['_thumbnail_id'], "the featured image of post {$id}");

                break;
            }
        }

        $active = in_array($post->post_status, ['publish', 'future'], true);

        if ($active && (string) ($post->post_password ?? '') !== '') {
            // WordPress shows such a post's title and hides its text behind the password; Atrium has no such gate.
            $active = false;
            $this->note("password:{$main}", "post {$main} is protected by a password; it moved unpublished so its text does not go public");
        }

        $sort = (int) $post->menu_order === 0 ? 500 : (int) $post->menu_order;
        $code = $index['codes'][$main];
        $lost = [];

        $values = match ($type->target) {
            Type::PAGES => [
                'name' => $name,
                'code' => $code,
                'content' => $this->translations($text),
                'template' => 'default',
                'active' => $active,
                'sort' => $sort,
                'source' => 'wordpress:'.$main,
            ],
            default => [
                'name' => $name,
                'code' => $code,
                'active' => $active,
                'active_from' => $this->moment($post),
                'sort' => $sort,
                'preview_picture' => $thumbnail,
                'preview_text' => $this->translations($excerpt),
                'detail_text' => $this->translations($text),
            ],
        };

        if ($type->target === Type::PAGES) {
            $lost = array_keys(array_filter(['the excerpt' => array_filter($excerpt) !== [], 'the featured image' => $thumbnail !== null]));
        }

        foreach ($type->fields as $field) {
            $value = $this->fieldValue($field, $members, $meta, $main);

            if ($type->generated()) {
                $values[$field->column] = $value;
                $files = [...$files, ...$this->files($value)];
            } elseif ($value !== null && $value !== [] && $value !== '') {
                $lost[] = $field->name;
            }
        }

        $terms = [];

        foreach ($members as $id) {
            foreach ($links[$id] ?? [] as $link) {
                $taxonomy = $this->terms[$link]->taxonomy ?? '';

                if (in_array($taxonomy, $type->taxonomies, true) && ($record = $this->termRecord($link)) !== null) {
                    $terms[$taxonomy][$record] = $record;
                }
            }
        }

        $authors = [];

        foreach ($members as $id) {
            $author = (int) $posts[$id]->post_author;

            if (isset($index['users'][$author])) {
                $authors[$author] = $author;
            }
        }

        $modified = max(array_map(static fn (int $id): string => $posts[$id]->post_modified, $members));

        return [
            'wordpress_id' => $main,
            'posts' => $members,
            'values' => $values,
            'parent' => $index['parents'][$main] ?? null,
            'terms' => array_map(static fn (array $ids): array => array_values($ids), $terms),
            'authors' => array_values($authors),
            'seo' => $this->seo->forPost($meta[$main] ?? [], $name[$default] ?? reset($name), $excerpt[$default] ?? reset($excerpt), $this->plan->home()),
            'paths' => $paths,
            'short' => $short,
            'targets' => $targets,
            'modified' => $modified,
            'lost' => $lost,
            'files' => array_values(array_unique(array_filter($files, static fn (string $file): bool => ! str_contains($file, '://')))),
        ];
    }

    /**
     * The groups, codes, parents and authors of a post type: what one
     * record needs to know about the others.
     *
     * @return array{groups:array<int,array<string,int>>,codes:array<int,mixed>,parents:array<int,?int>,authors:array<int,int>,users:array<int,true>}
     */
    private function index(Type $type): array
    {
        if (isset($this->indexes[$type->type])) {
            return $this->indexes[$type->type];
        }

        $source = $this->plan->source;
        $languages = $this->plan->languages;
        $rows = [];

        foreach (array_chunk($source->postIds($type->type), 500) as $chunk) {
            foreach ($source->posts($chunk) as $id => $post) {
                // Only what the index and the addresses need: not the texts.
                $rows[$id] = (object) Arr::only((array) $post, ['ID', 'post_author', 'post_date', 'post_title', 'post_status', 'post_name', 'post_parent', 'post_type', 'menu_order']);
            }
        }

        ksort($rows);
        $this->permalinks->remember($rows);

        $groups = [];

        foreach ($rows as $id => $row) {
            $group = $languages->postGroup($id);
            $language = $languages->ofPost($id);
            isset($groups[$group][$language]) ? $groups["post:{$id}"][$language] = $id : $groups[$group][$language] = $id;
        }

        $byMain = [];
        $mainOf = [];

        foreach ($groups as $members) {
            $main = $members[$languages->default()] ?? min($members);
            $byMain[$main] = $members;

            foreach ($members as $id) {
                $mainOf[$id] = $main;
            }
        }

        ksort($byMain);

        $parents = [];

        if ($type->hierarchical) {
            foreach ($byMain as $main => $members) {
                $parent = (int) $rows[$main]->post_parent;
                $parents[$main] = $mainOf[$parent] ?? null;

                if ($parent !== 0 && ! isset($mainOf[$parent])) {
                    $this->note("parent:{$main}", "post {$main} is a child of post {$parent}, which does not move (missing, deleted or of another type): it goes to the top level");
                }
            }

            // A loop of parents would hang every tree: the post that closes it goes to the top.
            foreach (array_keys($parents) as $main) {
                $seen = [];

                for ($cursor = $main; $cursor !== null; $cursor = $parents[$cursor] ?? null) {
                    if (isset($seen[$cursor])) {
                        $this->note("loop:{$cursor}", "post {$cursor} is its own ancestor: it goes to the top level");
                        $parents[$cursor] = null;
                    } else {
                        $seen[$cursor] = $cursor;
                    }
                }
            }
        }

        $codes = [];
        $used = $this->reserved($type);

        foreach ($byMain as $main => $members) {
            if ($type->target === Type::PAGES) {
                $slug = Content::slug((string) $rows[$main]->post_name, (string) $this->content->text($rows[$main]->post_title), 'page-'.$main);
                $codes[$main] = $this->unique($slug, $used['parent:'.($parents[$main] ?? 0)]);

                continue;
            }

            foreach ($members as $language => $id) {
                $slug = Content::slug((string) $rows[$id]->post_name, (string) $this->content->text($rows[$id]->post_title), $type->type.'-'.$id);
                $scope = $type->hierarchical ? "{$language}:{$parents[$main]}" : $language;
                $codes[$main][$language] = $this->unique($slug, $used[$scope]);
            }
        }

        $authors = array_values(array_unique(array_filter(array_map(static fn (object $row): int => (int) $row->post_author, $rows))));
        $users = array_fill_keys(array_keys($source->users($authors)), true);

        return $this->indexes[$type->type] = [
            'groups' => $byMain,
            'codes' => $codes,
            'parents' => $parents,
            'authors' => $authors,
            'users' => $users,
        ];
    }

    /**
     * Codes records that did not come from WordPress already hold, so an
     * imported page never takes the address of one made in Atrium.
     *
     * @return array<string,array<string,true>>
     */
    private function reserved(Type $type): array
    {
        if (! in_array($type->target, [Type::NEWS, Type::PAGES], true) || ! Schema::hasTable($type->table)) {
            return [];
        }

        $ours = Schema::hasTable('wordpress_map')
            ? DB::table('wordpress_map')->where('kind', 'post')->where('target_type', $type->model)->pluck('target_id')->all()
            : [];
        $used = [];

        foreach (DB::table($type->table)->whereNotIn('id', $ours)->get() as $row) {
            if ($type->target === Type::PAGES) {
                if ($row->parent_id === null) {
                    $used['parent:0'][$row->code] = true;
                }

                continue;
            }

            foreach ((array) json_decode((string) $row->code, true) as $language => $code) {
                $used[$language][$code] = true;
            }
        }

        return $used;
    }

    /**
     * A code not given out yet in its scope: hello-world, hello-world-2…
     *
     * @param  array<string,true>|null  $used
     *
     * @param-out array<string,true> $used
     */
    private function unique(string $code, ?array &$used): string
    {
        $used ??= [];
        $candidate = $code;

        for ($i = 2; $used[$candidate] ?? false; $i++) {
            $candidate = $code.'-'.$i;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * Texts by language, without the empty ones; null when all are.
     *
     * @param  array<string,string>  $texts
     * @return array<string,string>|null
     */
    private function translations(array $texts): ?array
    {
        $texts = array_filter($texts, static fn (string $text): bool => $text !== '');

        return $texts === [] ? null : $texts;
    }

    /**
     * The value of a custom field as its column keeps it.
     *
     * @param  array<string,int>  $members
     * @param  array<int,array<string,string>>  $meta
     */
    private function fieldValue(Field $field, array $members, array $meta, int $main): mixed
    {
        if ($field->translatable()) {
            $texts = [];

            foreach ($members as $language => $id) {
                $raw = $meta[$id][$field->name] ?? '';
                $texts[$language] = $field->kind === 'wysiwyg' ? $this->content->html($raw) : $this->content->localUploads(trim(Text::clean($raw)));
            }

            return $this->translations($texts);
        }

        // Untranslated values come from the main post, or the first translation that has one.
        $owner = $main;

        foreach ([$main, ...$members] as $id) {
            if (array_key_exists($field->name, $meta[$id] ?? [])) {
                $owner = $id;

                break;
            }
        }

        $values = $meta[$owner] ?? [];
        $raw = $values[$field->name] ?? null;
        $context = "the field {$field->name} of post {$owner}";

        if (Serialized::wasRepaired($raw)) {
            $this->note("field:{$field->name}:{$owner}", "{$context} was saved with wrong string lengths (a search and replace on the database); it was read after a repair");
        }

        return match (true) {
            in_array($field->kind, ['repeater', 'flexible_content'], true) => $this->rows($field, $values),
            $field->kind === 'group' => $this->group($field, $values),
            $raw === null || $raw === '' => null,
            in_array($field->kind, ['number', 'range'], true) => is_numeric($raw) ? (float) $raw : null,
            $field->kind === 'true_false' => $raw === '1',
            $field->kind === 'date_picker' => $this->date($raw),
            $field->kind === 'date_time_picker' => strtotime($raw) === false ? null : date('Y-m-d H:i:s', (int) strtotime($raw)),
            in_array($field->kind, ['image', 'file'], true) => is_numeric($raw) ? $this->attachmentUrl($raw, $context) : $this->content->uploadUrl($raw),
            $field->kind === 'gallery' => array_values(array_filter(array_map(fn (mixed $id): ?string => $this->attachmentUrl($id, $context), Serialized::toArray($raw)))),
            $field->kind === 'checkbox' || ($field->multiple && $field->kind === 'select') => array_values(array_map(static fn (mixed $value): string => Text::clean((string) (is_scalar($value) ? $value : '')), Serialized::toArray($raw))),
            $field->isJson() => $this->plain(Serialized::decode($raw)),
            default => Text::clean($raw),
        };
    }

    /**
     * Rows of a repeater (steps_0_step_text, steps_1_step_text…) or of
     * flexible content, whose own value lists the layouts.
     *
     * @param  array<string,string>  $meta
     * @return array<int,array<string,mixed>>|null
     */
    private function rows(Field $field, array $meta): ?array
    {
        $raw = $meta[$field->name] ?? '';
        $layouts = $field->kind === 'flexible_content' ? array_values(Serialized::toArray($raw)) : [];
        $count = $field->kind === 'flexible_content' ? count($layouts) : (int) (is_numeric($raw) ? $raw : null);
        $rows = [];

        // A list of the row numbers rather than a counting loop: a loop that
        // never ends would take all the memory of the machine.
        foreach ($count > 0 ? range(0, $count - 1) : [] as $i) {
            $row = $layouts === [] ? [] : ['acf_fc_layout' => (string) (is_scalar($layouts[$i]) ? $layouts[$i] : '')];
            $prefix = $field->name.'_'.$i.'_';

            foreach ($meta as $key => $value) {
                if (str_starts_with($key, $prefix)) {
                    $row[substr($key, strlen($prefix))] = $this->plain(Serialized::decode($value));
                }
            }

            $rows[] = $row;
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * @param  array<string,string>  $meta
     * @return array<string,mixed>|null
     */
    private function group(Field $field, array $meta): ?array
    {
        $values = [];

        foreach ($field->subFields as $sub) {
            if (array_key_exists($field->name.'_'.$sub, $meta)) {
                $values[$sub] = $this->plain(Serialized::decode($meta[$field->name.'_'.$sub]));
            }
        }

        return $values === [] ? null : $values;
    }

    /**
     * A decoded value with its texts in UTF-8.
     */
    private function plain(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(fn (mixed $item): mixed => $this->plain($item), $value),
            is_string($value) => Text::clean($value),
            default => $value,
        };
    }

    /**
     * ACF keeps dates as 20240115.
     */
    private function date(string $raw): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        $time = strtotime($raw);

        return $time === false ? null : date('Y-m-d', $time);
    }

    /**
     * Files of the old uploads folder a field value links to.
     *
     * @return array<int,string>
     */
    private function files(mixed $value): array
    {
        $files = [];
        $values = is_array($value) ? $value : [$value];

        array_walk_recursive($values, static function (mixed $item) use (&$files): void {
            if (is_string($item)) {
                $files = [...$files, ...Content::referencedUploads($item)];
            }
        });

        return $files;
    }

    /**
     * When the post was published, in the new site's time zone. WordPress
     * keeps it twice: as the wall clock of the site and in UTC; drafts have
     * no UTC time yet.
     */
    private function moment(object $post): string
    {
        $utc = (string) ($post->post_date_gmt ?? '');

        if ($utc === '' || str_starts_with($utc, '0000')) {
            return (string) $post->post_date;
        }

        return Carbon::parse($utc, 'UTC')->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function note(string $key, string $text): void
    {
        $this->notes[$key] = $text;
    }
}
