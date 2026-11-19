<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Import\Records;
use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Source\Source;
use Atrium\WordPress\Tests\Concerns\ImportsWordPress;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress as Wp;
use Atrium\WordPress\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a source may do that the made-up database does not: give the numbers
 * of a row as text (a driver that does not turn them into integers) and give
 * the rows in any order (a database promises none without an ORDER BY).
 * Whatever it does, the records are the same.
 */
final class RecordsRowsTest extends TestCase
{
    use ImportsWordPress;

    /**
     * @return array<int,array<string,mixed>> main post ID => record
     */
    private function everything(Records $records, string $type): array
    {
        $all = [];

        foreach ($records->records($records->plan->types[$type]) as $chunk) {
            $all += $chunk;
        }

        return $all;
    }

    /**
     * @param  array<int,string>  $without  columns of posts the source does not have
     * @param  array<string,mixed>  $set  columns of posts it gives other values for
     */
    private function through(bool $text, bool $reversed, array $without = [], array $set = []): Records
    {
        $plan = $this->plan();

        return new Records(new Plan(new RowsSource($plan->source, $text, $reversed, $without, $set), $plan->languages, $plan->types, $plan->taxonomies, $plan->authorsTable, $plan->authorsModel));
    }

    public function test_numbers_given_as_text_make_the_same_records(): void
    {
        $this->site();
        $plain = $this->records();
        $text = $this->through(true, false);

        foreach (['post', 'page', 'recipe'] as $type) {
            $this->assertSame($this->everything($plain, $type), $this->everything($text, $type), $type);
        }

        $this->assertSame($plain->authors(), $text->authors());
        $this->assertSame($plain->attachments(), $text->attachments());
        $this->assertSame($plain->terms($plain->plan->taxonomies['category']), $text->terms($text->plan->taxonomies['category']));
        $this->assertSame($plain->notes(), $text->notes());
    }

    public function test_a_gallery_of_the_pictures_attached_to_a_post_is_the_same_for_any_source(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_content' => "Look:\n\n[gallery]"]);
        $figure = '<figure><img src="/wp-content/uploads/2024/05/photo.jpg" alt="A photo of the street"></figure>';

        foreach ([$this->records(), $this->through(true, false), $this->through(false, true), $this->through(true, true)] as $records) {
            $this->assertSame(['en' => "<p>Look:</p>\n{$figure}", 'ru' => '<p>Первый абзац.</p>'], $this->everything($records, 'post')[Wp::HELLO]['values']['detail_text']);
        }
    }

    /**
     * @return array<string,array{0:array<int,string>,1:array<string,mixed>}>
     */
    public static function sourcesWithoutPasswordsOrUtcTimes(): array
    {
        return [
            'without the columns' => [['post_password', 'post_date_gmt'], []],
            'with nothing in them' => [[], ['post_password' => null, 'post_date_gmt' => null]],
        ];
    }

    /**
     * @param  array<int,string>  $without
     * @param  array<string,mixed>  $set
     */
    #[DataProvider('sourcesWithoutPasswordsOrUtcTimes')]
    public function test_a_post_without_a_password_or_a_utc_time_is_published_at_its_local_time(array $without, array $set): void
    {
        $this->site();
        config(['app.timezone' => 'Europe/Moscow']);
        // 06:00 in UTC would be 09:00 in Moscow, the local time: the time of the clock has to be told from the converted one.
        $this->wp()->table('wp_posts')->where('ID', Wp::PHOTO)->update(['post_date_gmt' => '2024-05-10 01:00:00']);
        $this->wpPost(450, 'book', 'Open', ['post_date' => '2024-05-10 10:00:00', 'post_date_gmt' => '2024-05-10 04:00:00']);

        $records = $this->through(false, false, $without, $set);
        $book = $this->everything($records, 'book')[450]['values'];

        $this->assertTrue($book['active']);
        $this->assertSame('2024-05-10 10:00:00', $book['active_from']);
        $this->assertSame('2024-05-10 09:00:00', $records->attachments()[Wp::PHOTO]['date']);
        $this->assertSame([], array_values(array_filter($records->notes(), static fn (string $note): bool => str_contains($note, 'password'))));
    }

    public function test_a_source_that_gives_the_utc_time_as_an_object_with_a_text_form_is_read_as_that_text(): void
    {
        $this->site();
        config(['app.timezone' => 'Europe/Moscow']);
        $this->wpPost(450, 'book', 'Open', ['post_date' => '2024-05-10 10:00:00']);

        $book = $this->everything($this->through(false, false, [], ['post_date_gmt' => Carbon::parse('2024-05-10 04:30:00', 'UTC')]), 'book')[450]['values'];

        $this->assertSame('2024-05-10 07:30:00', $book['active_from']);
    }

    public function test_a_post_without_any_dates_is_active_from_nothing(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'Open');

        $book = $this->everything($this->through(false, false, [], ['post_date_gmt' => null, 'post_date' => null]), 'book')[450]['values'];

        $this->assertSame('', $book['active_from']);
    }

    public function test_a_gallery_without_ids_skips_an_attachment_that_has_no_mime_type(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_content' => '[gallery]']);

        $with = $this->everything($this->through(false, false), 'post')[Wp::HELLO]['values']['detail_text'];
        $without = $this->everything($this->through(false, false, [], ['post_mime_type' => null]), 'post')[Wp::HELLO]['values']['detail_text'];

        $this->assertSame(['en' => '<figure><img src="/wp-content/uploads/2024/05/photo.jpg" alt="A photo of the street"></figure>', 'ru' => '<p>Первый абзац.</p>'], $with);
        $this->assertSame(['ru' => '<p>Первый абзац.</p>'], $without);
    }

    public function test_a_gallery_with_ids_skips_an_attachment_that_has_no_mime_type(): void
    {
        $this->site();
        $this->wp()->table('wp_posts')->where('ID', Wp::HELLO)->update(['post_content' => '[gallery ids="50"]']);

        $without = $this->everything($this->through(false, false, [], ['post_mime_type' => null]), 'post')[Wp::HELLO]['values']['detail_text'];

        $this->assertSame(['ru' => '<p>Первый абзац.</p>'], $without);
    }

    public function test_a_post_behind_a_password_is_protected_and_its_time_converted_for_a_source_that_gives_text(): void
    {
        $this->site();
        config(['app.timezone' => 'Europe/Moscow']);
        $this->wpPost(450, 'book', 'Shut', ['post_date' => '2024-05-10 10:00:00', 'post_date_gmt' => '2024-05-10 04:00:00', 'post_password' => 'pass']);

        $records = $this->through(true, false);
        $book = $this->everything($records, 'book')[450]['values'];

        $this->assertFalse($book['active']);
        $this->assertSame('2024-05-10 07:00:00', $book['active_from']);
        $this->assertSame('2024-05-10 09:00:00', $records->attachments()[Wp::PHOTO]['date']);
    }

    public function test_a_menu_order_given_as_text_is_still_a_number_and_zero_still_the_middle(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'Zero', ['menu_order' => 0]);
        $this->wpPost(451, 'book', 'Seven', ['menu_order' => 7]);

        $books = $this->everything($this->through(true, false), 'book');

        $this->assertSame([500, 7], [$books[450]['values']['sort'], $books[451]['values']['sort']]);
    }

    public function test_the_authors_of_posts_given_as_text_are_numbers(): void
    {
        $this->site();
        $this->wpPost(450, 'book', 'Nobody', ['post_author' => 0]);

        $books = $this->everything($this->through(true, false), 'book');

        $this->assertSame([], $books[450]['authors']);
        $this->assertSame([Wp::USER_BORIS], $this->everything($this->through(true, false), 'post')[Wp::GUTENBERG]['authors']);
    }

    public function test_a_parent_given_as_text_is_a_number(): void
    {
        $this->site();

        $records = $this->through(true, false);
        $records->count($records->plan->types['page']);

        $this->assertSame(['post 34 is a child of post 999, which does not move (missing, deleted or of another type): it goes to the top level'], $records->notes());
    }

    public function test_rows_given_in_any_order_make_the_records_in_the_order_of_their_ids(): void
    {
        $this->site();
        $this->wp()->table('wp_icl_translations')->where('element_type', 'post_post')->where('element_id', Wp::HELLO_RU)->update(['language_code' => 'en']);
        $plain = $this->records();
        $reversed = $this->through(false, true);

        $posts = $this->everything($reversed, 'post');

        $this->assertSame(array_keys($this->everything($plain, 'post')), array_keys($posts));
        $this->assertSame($this->sorted(array_keys($posts)), array_keys($posts));
        // The first of two posts in one language is the one in the group.
        $this->assertSame(['en' => Wp::HELLO], $posts[Wp::HELLO]['posts']);
        $this->assertSame(['en' => Wp::HELLO_RU], $posts[Wp::HELLO_RU]['posts']);
        $this->assertSame($this->everything($plain, 'post'), $posts);
    }

    /**
     * Books 700 (Russian) and 702 (English) are one text in two languages, 701 is another;
     * 710 and 711 are both English and 712 Russian: one group of three, the second English one apart.
     */
    private function books(): void
    {
        foreach ([700, 701, 702, 710, 711, 712] as $id) {
            $this->wpPost($id, 'book', 'Book '.$id);
        }

        $this->wp()->table('wp_icl_translations')->insert([
            ['element_type' => 'post_book', 'element_id' => 700, 'trid' => 5000, 'language_code' => 'ru'],
            ['element_type' => 'post_book', 'element_id' => 702, 'trid' => 5000, 'language_code' => 'en'],
            ['element_type' => 'post_book', 'element_id' => 701, 'trid' => 5001, 'language_code' => 'en'],
            ['element_type' => 'post_book', 'element_id' => 710, 'trid' => 5002, 'language_code' => 'en'],
            ['element_type' => 'post_book', 'element_id' => 711, 'trid' => 5002, 'language_code' => 'en'],
            ['element_type' => 'post_book', 'element_id' => 712, 'trid' => 5002, 'language_code' => 'ru'],
        ]);
    }

    public function test_the_records_are_in_the_order_of_the_ids_of_their_main_posts_not_of_their_first_posts(): void
    {
        $this->site();
        $this->books();

        $books = $this->everything($this->records(), 'book');

        // The group of 700 is met first and headed by 702.
        $this->assertSame([701, 702, 710, 711], array_keys($books));
        $this->assertSame(['ru' => 700, 'en' => 702], $books[702]['posts']);
    }

    public function test_of_two_posts_in_one_language_in_one_group_the_lower_id_stays_in_the_group_whatever_the_order_of_the_rows(): void
    {
        $this->site();
        $this->books();

        $books = $this->everything($this->through(false, true), 'book');

        $this->assertSame([701, 702, 710, 711], array_keys($books));
        $this->assertSame(['en' => 710, 'ru' => 712], $books[710]['posts']);
        $this->assertSame(['en' => 711], $books[711]['posts']);
    }

    public function test_users_given_in_any_order_are_authors_in_the_order_of_their_ids(): void
    {
        $this->site();

        $this->assertSame([Wp::USER_ALICE, Wp::USER_BORIS], array_keys($this->through(false, true)->authors()));
    }

    public function test_attachments_given_in_any_order_are_in_the_order_of_their_ids(): void
    {
        $this->site();

        $this->assertSame([Wp::PHOTO, Wp::BORSCHT_PHOTO, Wp::MISSING_PHOTO, Wp::MANUAL], array_keys($this->through(false, true)->attachments()));
    }

    /**
     * @param  array<int,int>  $ids
     * @return array<int,int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}

/**
 * The source it wraps, its rows changed: numbers as text, in the opposite order.
 */
final class RowsSource implements Source
{
    /**
     * @param  array<int,string>  $without
     * @param  array<string,mixed>  $set
     */
    public function __construct(private readonly Source $inner, private readonly bool $text, private readonly bool $reversed, private readonly array $without = [], private readonly array $set = []) {}

    /**
     * @param  array<int|string,object>  $rows
     * @return array<int|string,object>
     */
    private function edit(array $rows): array
    {
        return array_map(function (object $row): object {
            $copy = clone $row;

            foreach ($this->without as $column) {
                unset($copy->{$column});
            }

            foreach ($this->set as $column => $value) {
                $copy->{$column} = $value;
            }

            return $copy;
        }, $rows);
    }

    /**
     * @param  array<int|string,object>  $rows
     * @return array<int|string,object>
     */
    private function change(array $rows): array
    {
        if ($this->reversed) {
            $rows = array_reverse($rows, true);
        }

        if (! $this->text) {
            return $rows;
        }

        return array_map(static function (object $row): object {
            $copy = clone $row;

            foreach (get_object_vars($copy) as $name => $value) {
                if (is_int($value)) {
                    $copy->{$name} = (string) $value;
                }
            }

            return $copy;
        }, $rows);
    }

    public function option(string $name): ?string
    {
        return $this->inner->option($name);
    }

    public function postTypeCounts(array $statuses = self::STATUSES): array
    {
        return $this->inner->postTypeCounts($statuses);
    }

    public function postIds(string $type, array $statuses = self::STATUSES): array
    {
        return $this->inner->postIds($type, $statuses);
    }

    public function parents(string $type): array
    {
        return $this->inner->parents($type);
    }

    public function metaKeys(string $type): array
    {
        return $this->inner->metaKeys($type);
    }

    public function posts(array $ids): array
    {
        return $this->change($this->edit($this->inner->posts($ids)));
    }

    public function meta(array $ids): array
    {
        return $this->inner->meta($ids);
    }

    public function terms(): array
    {
        return $this->text ? array_values($this->change($this->inner->terms())) : $this->inner->terms();
    }

    public function termMeta(): array
    {
        return $this->inner->termMeta();
    }

    public function relationships(array $ids): array
    {
        return $this->inner->relationships($ids);
    }

    public function objectsInTaxonomy(string $taxonomy): array
    {
        return $this->inner->objectsInTaxonomy($taxonomy);
    }

    public function users(array $ids): array
    {
        return $this->change($this->inner->users($ids));
    }

    public function wpmlTranslations(): ?array
    {
        return $this->inner->wpmlTranslations();
    }

    public function permalink(int $id): ?string
    {
        return $this->inner->permalink($id);
    }
}
