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

    private function through(bool $text, bool $reversed): Records
    {
        $plan = $this->plan();

        return new Records(new Plan(new RowsSource($plan->source, $text, $reversed), $plan->languages, $plan->types, $plan->taxonomies, $plan->authorsTable, $plan->authorsModel));
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
    public function __construct(private readonly Source $inner, private readonly bool $text, private readonly bool $reversed) {}

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
        return $this->change($this->inner->posts($ids));
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
