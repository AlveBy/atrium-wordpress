<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Unit;

use Atrium\WordPress\Import\Content;
use Atrium\WordPress\Tests\Fixtures\SyntheticWordPress;
use Atrium\WordPress\Tests\LightTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ContentTest extends LightTestCase
{
    private const OLD = 'https://old.example.com';

    public function test_files_are_served_from_the_configured_folder(): void
    {
        $this->assertSame('/wp-content/uploads', Content::uploadsBase());

        config()->set('atrium-wordpress.uploads_target', 'media/wp');
        $this->assertSame('/media/wp', Content::uploadsBase());

        config()->set('atrium-wordpress.uploads_target', '/media/wp/');
        $this->assertSame('/media/wp', Content::uploadsBase());
    }

    public function test_empty_content_stays_empty(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('', $content->html(null));
        $this->assertSame('', $content->html(''));
        $this->assertSame('', $content->html("  \n\n  "));
    }

    public function test_block_comments_are_taken_out(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>A</p>', $content->html("<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->"));
        $this->assertSame('<p>A</p>', $content->html('<!-- wp:paragraph {"align":"center"} --><p>A</p><!-- /wp:paragraph -->'));
        $this->assertSame('<p>A</p>', $content->html('<!--wp:paragraph--><p>A</p><!--/wp:paragraph-->'));
        $this->assertSame('<p>A</p>', $content->html('<!--   wp:x   --><p>A</p><!--   /wp:x   -->'));
        $this->assertSame('<p>A</p>', $content->html('<!-- wp:core/group --><p>A</p><!-- /wp:core/group -->'));
        // A selfclosing block.
        $this->assertSame('<p>A</p>', $content->html('<!-- wp:more /--><p>A</p>'));
    }

    public function test_other_comments_stay(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>A</p><!-- more --><p>B</p>', $content->html('<p>A</p><!-- more --><p>B</p>'));
        $this->assertSame('<p><!-- note: wp is great --></p>', $content->html('<p><!-- note: wp is great --></p>'));
    }

    public function test_line_endings_and_blank_lines_are_tidied(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame("<p>A</p>\n\n<p>B</p>", $content->html("<p>A</p>\r\n\r\n\r\n\r\n<p>B</p>"));
        $this->assertSame("<p>A</p>\n\n<p>B</p>", $content->html("<p>A</p>\r\r\r<p>B</p>"));
        $this->assertSame("<p>A</p>\n\n<p>B</p>", $content->html("<p>A</p>\n\n<p>B</p>"));
        $this->assertSame("<p>A</p>\n<p>B</p>", $content->html("  <p>A</p>\n<p>B</p>  "));
    }

    public function test_plain_text_gets_paragraphs_as_wpautop_would_add_them(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>One line</p>', $content->html('One line'));
        $this->assertSame("<p>First paragraph.</p>\n<p>Second paragraph<br>\nwith a line break.</p>", $content->html(SyntheticWordPress::HELLO_CONTENT));
        $this->assertSame("<p>a</p>\n<p>b</p>", $content->html("a\n\n\n\nb"));
        $this->assertSame("<p>a</p>\n<p>b</p>", $content->html("a\n  \nb"));
        $this->assertSame("<p>a</p>\n<p>b</p>", $content->html("\n\na\n\nb\n\n"));
        $this->assertSame('<p>Привет</p>', $content->html('Привет'));
        $this->assertSame('<p>x <span>inline</span> y</p>', $content->html('x <span>inline</span> y'));
        $this->assertSame('<p><preview>x</preview></p>', $content->html('<preview>x</preview>'), 'pre is a block tag, preview is not');
        $this->assertSame('<p><a href="/x">link</a></p>', $content->html('<a href="/x">link</a>'));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function blockHtml(): array
    {
        return [
            'paragraph' => ['<p>x</p>'],
            'paragraph with a class' => ['<p class="a">x</p>'],
            'upper case' => ['<P>x</P>'],
            'div' => ['<div>x</div>'],
            'h1' => ['<h1>x</h1>'],
            'h3' => ['<h3>x</h3>'],
            'h6' => ['<h6>x</h6>'],
            'ul' => ['<ul><li>x</li></ul>'],
            'ol' => ['<ol><li>x</li></ol>'],
            'table' => ['<table><tr><td>x</td></tr></table>'],
            'blockquote' => ['<blockquote>x</blockquote>'],
            'figure' => ['<figure>x</figure>'],
            'pre' => ['<pre>x</pre>'],
            'section' => ['<section>x</section>'],
            'article' => ['<article>x</article>'],
            'hr' => ['x<hr>y'],
            'self-closing hr' => ['x<hr/>y'],
            'dl' => ['<dl><dt>x</dt></dl>'],
            'tag after a newline' => ["x\n<div\nclass=\"a\">y</div>"],
        ];
    }

    #[DataProvider('blockHtml')]
    public function test_html_with_block_tags_keeps_its_own_structure(string $html): void
    {
        $this->assertSame($html, (new Content(self::OLD))->html($html));
    }

    public function test_blocks_after_the_comments_are_gone_are_not_wrapped_again(): void
    {
        $html = "<!-- wp:list -->\n<ul><li>x</li></ul>\n<!-- /wp:list -->";

        $this->assertSame('<ul><li>x</li></ul>', (new Content(self::OLD))->html($html));
    }

    public function test_the_text_is_valid_utf8(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>'.SyntheticWordPress::ENCODING_CONTENT.'</p>', $content->html(SyntheticWordPress::encodingContent()));
        $this->assertSame('<p>'.SyntheticWordPress::ENCODING_TITLE.'</p>', $content->html(SyntheticWordPress::encodingTitle()));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function uploadLinks(): array
    {
        return [
            'https' => ['<img src="https://old.example.com/wp-content/uploads/a.jpg">', '<img src="/wp-content/uploads/a.jpg">'],
            'http' => ['<img src="http://old.example.com/wp-content/uploads/a.jpg">', '<img src="/wp-content/uploads/a.jpg">'],
            'without a scheme' => ['<img src="//old.example.com/wp-content/uploads/a.jpg">', '<img src="/wp-content/uploads/a.jpg">'],
            'with www' => ['<img src="https://www.old.example.com/wp-content/uploads/a.jpg">', '<img src="/wp-content/uploads/a.jpg">'],
            'upper case host' => ['<img src="https://OLD.Example.COM/wp-content/uploads/a.jpg">', '<img src="/wp-content/uploads/a.jpg">'],
            'upper case path' => ['<img src="https://old.example.com/WP-Content/Uploads/a.jpg">', '<img src="/wp-content/uploads/a.jpg">'],
            'two links' => ['<a href="https://old.example.com/wp-content/uploads/a.pdf">a</a> <a href="http://old.example.com/wp-content/uploads/b.pdf">b</a>', '<a href="/wp-content/uploads/a.pdf">a</a> <a href="/wp-content/uploads/b.pdf">b</a>'],
            'another host stays' => ['<img src="https://cdn.other.test/wp-content/uploads/a.jpg">', '<img src="https://cdn.other.test/wp-content/uploads/a.jpg">'],
            'a subdomain of the old host stays' => ['<img src="https://cdn.old.example.com/wp-content/uploads/a.jpg">', '<img src="https://cdn.old.example.com/wp-content/uploads/a.jpg">'],
            'a host that only ends like the old one stays' => ['<img src="https://notold.example.com/wp-content/uploads/a.jpg">', '<img src="https://notold.example.com/wp-content/uploads/a.jpg">'],
            'a page of the old host stays' => ['<a href="https://old.example.com/about/">a</a>', '<a href="https://old.example.com/about/">a</a>'],
            'the themes of the old host stay' => ['<img src="https://old.example.com/wp-content/themes/a.jpg">', '<img src="https://old.example.com/wp-content/themes/a.jpg">'],
            'the old host in the middle of a text stays' => ['visit old.example.com/wp-content/uploads/a.jpg', 'visit old.example.com/wp-content/uploads/a.jpg'],
        ];
    }

    #[DataProvider('uploadLinks')]
    public function test_links_to_old_uploads_point_at_the_copied_files(string $html, string $expected): void
    {
        $this->assertSame($expected, (new Content(self::OLD))->localUploads($html));
        // As part of a post, after the paragraphs are made: the same links.
        $this->assertSame('<div>'.$expected.'</div>', (new Content(self::OLD))->html('<div>'.$html.'</div>'));
    }

    public function test_every_address_of_the_old_site_is_rewritten(): void
    {
        $content = new Content('https://old.example.com', 'http://www.old-two.example.com/blog');
        $html = '<a href="https://old.example.com/wp-content/uploads/a.jpg"><a href="https://old-two.example.com/wp-content/uploads/b.jpg">';

        $this->assertSame('<a href="/wp-content/uploads/a.jpg"><a href="/wp-content/uploads/b.jpg">', $content->localUploads($html));
    }

    public function test_without_an_address_nothing_is_rewritten(): void
    {
        $html = '<img src="https://old.example.com/wp-content/uploads/a.jpg">';

        $this->assertSame($html, (new Content)->localUploads($html));
        $this->assertSame($html, (new Content('', 'not a url'))->localUploads($html));
    }

    public function test_uploads_are_rewritten_to_the_configured_folder(): void
    {
        config()->set('atrium-wordpress.uploads_target', 'media');

        $this->assertSame('<img src="/media/a.jpg">', (new Content(self::OLD))->localUploads('<img src="https://old.example.com/wp-content/uploads/a.jpg">'));
    }

    public function test_a_title_is_trimmed_and_valid_utf8(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('', $content->text(null));
        $this->assertSame('', $content->text('  '));
        $this->assertSame('Tom &amp; Jerry', $content->text("  Tom &amp; Jerry \n"));
        $this->assertSame(SyntheticWordPress::ENCODING_TITLE, $content->text(SyntheticWordPress::encodingTitle()));
        $this->assertSame('<b>x</b>', $content->text('<b>x</b>'), 'no tags are stripped, no paragraphs are made');
    }

    public function test_an_address_of_a_file_is_a_path_on_the_new_site(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('/wp-content/uploads/a.jpg', $content->uploadUrl('https://old.example.com/wp-content/uploads/a.jpg'));
        $this->assertSame('/wp-content/uploads/a.jpg', $content->uploadUrl('  https://old.example.com/wp-content/uploads/a.jpg  '));
        $this->assertSame('/wp-content/uploads/a.jpg', $content->uploadUrl('/wp-content/uploads/a.jpg'));
        $this->assertSame('https://cdn.other.test/a.jpg', $content->uploadUrl('https://cdn.other.test/a.jpg'));
        $this->assertSame('https://old.example.com/about/', $content->uploadUrl('https://old.example.com/about/'));
        $this->assertSame('/images/a.jpg', $content->uploadUrl('/images/a.jpg'));
        $this->assertSame('', $content->uploadUrl(''));
        $this->assertSame('', $content->uploadUrl('   '));
    }

    public function test_an_address_of_a_file_follows_the_configured_folder(): void
    {
        config()->set('atrium-wordpress.uploads_target', 'media');
        $content = new Content(self::OLD);

        $this->assertSame('/media/a.jpg', $content->uploadUrl('https://old.example.com/wp-content/uploads/a.jpg'));
        $this->assertSame('/media/2024/a.jpg', $content->uploadUrl('/wp-content/uploads/2024/a.jpg'));
        $this->assertSame('/media/a.jpg', $content->uploadUrl('/media/a.jpg'));
    }

    public function test_the_files_a_text_links_to_are_listed(): void
    {
        $html = '<img src="/wp-content/uploads/2024/05/a.jpg"> <a href="/wp-content/uploads/docs/b.pdf?x=1">b</a>'
            .' <a href=\'/wp-content/uploads/c.png\'>c</a> (/wp-content/uploads/d.gif) /wp-content/uploads/e.gif#top'
            .' <i>/wp-content/uploads/f.gif</i> /wp-content/uploads/a.jpg "/wp-content/uploads/2024/05/a.jpg"';

        $this->assertSame(['2024/05/a.jpg', 'docs/b.pdf', 'c.png', 'd.gif', 'e.gif', 'f.gif', 'a.jpg'], Content::referencedUploads($html));
    }

    public function test_listed_files_are_decoded(): void
    {
        $this->assertSame(['my file/ä.jpg'], Content::referencedUploads('<img src="/wp-content/uploads/my%20file/%C3%A4.jpg">'));
    }

    public function test_no_link_no_files(): void
    {
        $this->assertSame([], Content::referencedUploads(''));
        $this->assertSame([], Content::referencedUploads('<img src="/images/a.jpg"> /wp-content/themes/x.css'));
        $this->assertSame([], Content::referencedUploads('<img src="/wp-content/uploads/">'));
    }

    public function test_listed_files_follow_the_configured_folder(): void
    {
        config()->set('atrium-wordpress.uploads_target', 'media');

        $this->assertSame(['a.jpg'], Content::referencedUploads('<img src="/media/a.jpg"> <img src="/wp-content/uploads/b.jpg">'));
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    public static function slugs(): array
    {
        return [
            'a slug' => ['hello-world', 'Hello World', 'post-1', 'hello-world'],
            'an upper case slug' => ['Hello_World', 'T', 'post-1', 'hello-world'],
            'a slug WordPress URL-encoded' => [SyntheticWordPress::HELLO_RU_SLUG_ENCODED, 'Привет, мир', 'post-1', 'privet-mir'],
            'no slug: the title' => ['', 'Привет, мир', 'post-1', 'privet-mir'],
            'no slug, a Latin title' => ['', 'Hello, World!', 'post-1', 'hello-world'],
            'neither' => ['', '', 'post-7', 'post-7'],
            'nothing a slug could be made of' => ['', '!!!', 'post-8', 'post-8'],
            'a slug of symbols' => ['%21%21', '!!!', 'post-9', 'post-9'],
            'a slug of symbols with a title' => ['%21%21', 'Fine', 'post-9', 'fine'],
        ];
    }

    #[DataProvider('slugs')]
    public function test_a_code_comes_from_the_slug(string $postName, string $title, string $fallback, string $expected): void
    {
        $this->assertSame($expected, Content::slug($postName, $title, $fallback));
    }

    public function test_a_code_is_at_most_200_characters(): void
    {
        $this->assertSame(str_repeat('a', 200), Content::slug(str_repeat('a', 300), '', 'x'));
        $this->assertSame(str_repeat('a', 200), Content::slug('', str_repeat('a', 300), 'x'));
        $this->assertSame(str_repeat('a', 200), Content::slug(str_repeat('a', 200), '', 'x'));
        $this->assertSame(str_repeat('a', 199), Content::slug(str_repeat('a', 199), '', 'x'));
        $this->assertSame(str_repeat('a', 300), Content::slug('', '', str_repeat('a', 300)), 'the fallback is kept whole');
    }

    public function test_a_line_break_in_a_text_from_windows_is_one_line_break(): void
    {
        $this->assertSame("<p>one<br>\ntwo</p>\n<p>three</p>", (new Content(self::OLD))->html("one\r\ntwo\r\n\r\nthree"));
    }

    public function test_paragraphs_lose_the_spaces_around_them(): void
    {
        $this->assertSame("<p>First</p>\n<p>Second</p>\n<p>Third</p>", (new Content(self::OLD))->html("First  \n\n  Second  \n\nThird"));
    }

    public function test_a_paragraph_of_nothing_visible_is_not_made(): void
    {
        $this->assertSame("<p>A</p>\n<p>B</p>", (new Content(self::OLD))->html("A\n\n\0\n\nB"));
    }

    public function test_the_old_address_is_matched_as_written_not_as_a_pattern(): void
    {
        $html = '<img src="https://oldXexample.com/wp-content/uploads/a.jpg"><img src="https://old.example.com/wp-content/uploads/b.jpg">';

        $this->assertSame('<img src="https://oldXexample.com/wp-content/uploads/a.jpg"><img src="/wp-content/uploads/b.jpg">', (new Content(self::OLD))->localUploads($html));
    }

    public function test_the_old_address_may_be_written_with_www_and_capitals(): void
    {
        $content = new Content('HTTPS://WWW.Old.Example.com/Blog');

        $this->assertSame('<img src="/wp-content/uploads/a.jpg"><img src="/wp-content/uploads/b.jpg">', $content->localUploads('<img src="http://old.example.com/wp-content/uploads/a.jpg"><img src="//www.old.example.com/wp-content/uploads/b.jpg">'));
    }

    public function test_the_same_old_address_twice_changes_nothing(): void
    {
        $content = new Content('https://old.example.com', 'http://www.old.example.com/other');

        $this->assertSame('<img src="/wp-content/uploads/a.jpg">', $content->localUploads('<img src="https://old.example.com/wp-content/uploads/a.jpg">'));
    }

    public function test_listed_files_are_matched_in_the_configured_folder_as_written(): void
    {
        config()->set('atrium-wordpress.uploads_target', 'media.v2');

        $this->assertSame(['b.jpg'], Content::referencedUploads('<img src="/mediaXv2/a.jpg"> <img src="/media.v2/b.jpg">'));
    }

    public function test_a_file_linked_twice_is_listed_once_and_the_list_has_no_gaps(): void
    {
        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], Content::referencedUploads('/wp-content/uploads/a.jpg /wp-content/uploads/b.jpg /wp-content/uploads/a.jpg /wp-content/uploads/c.jpg'));
    }

    public function test_an_address_without_a_host_is_no_old_address(): void
    {
        $html = '<img src="///wp-content/uploads/a.jpg">';

        $this->assertSame($html, (new Content('not a url', '/relative/path'))->localUploads($html));
    }
}
