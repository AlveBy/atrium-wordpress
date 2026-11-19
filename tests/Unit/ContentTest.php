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
            'dl' => ['<dl><dt>x</dt></dl>'],
            'hr alone' => ['<hr>'],
            'self-closing hr alone' => ['<hr/>'],
            'tag with attributes on the next line' => ["<div\nclass=\"a\">y</div>"],
            'blocks with blank lines between' => ["<p>A</p>\n\n<p>B</p>"],
            'blocks with a line break between' => ["<p>A</p>\n<p>B</p>"],
            'blocks side by side' => ['<p>A</p><p>B</p>'],
            'blocks with a space between' => ['<p>A</p> <p>B</p>'],
            'blocks with a comment between' => ["<p>A</p>\n\n<!-- c -->\n\n<p>B</p>"],
            'a comment alone' => ['<!-- c -->'],
            'a list with its own line breaks' => ["<ul>\n<li>x</li>\n</ul>"],
            'a more tag alone' => ['<!--more-->'],
            'blocks with a more tag between' => ['<p>A</p><!--more--><p>B</p>'],
            'a more tag with a text alone' => ['<!--more Read on-->'],
            'a pre alone' => ["<pre>a\n\nb</pre>"],
            'a script alone' => ['<script>a</script>'],
            'a style alone' => ["<style>a\n\nb</style>"],
            'a textarea alone' => ["<textarea>a\n\nb</textarea>"],
            'a more-like comment alone' => ['<!--moreover-->'],
            'an hr between blocks' => ['<p>A</p><hr><p>B</p>'],
            'an hr between blocks, blank lines around' => ["<p>A</p>\n\n<hr>\n\n<p>B</p>"],
            'two hr' => ['<hr><hr>'],
            'two hr with a line break between' => ["<hr>\n<hr>"],
            'a comment of several lines' => ["<!-- a\nb -->"],
        ];
    }

    #[DataProvider('blockHtml')]
    public function test_html_with_block_tags_keeps_its_own_structure(string $html): void
    {
        $this->assertSame($html, (new Content(self::OLD))->html($html));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function textAmongBlocks(): array
    {
        return [
            'before a block' => ['x<div>y</div>', '<p>x</p><div>y</div>'],
            'after a block' => ['<div>y</div>x', '<div>y</div><p>x</p>'],
            'between blocks' => ['<p>A</p>B<p>C</p>', '<p>A</p><p>B</p><p>C</p>'],
            'around a block' => ['x<h2>y</h2>z', '<p>x</p><h2>y</h2><p>z</p>'],
            'upper case block' => ['x<P>y</P>z', '<p>x</p><P>y</P><p>z</p>'],
            'before an hr' => ['x<hr>', '<p>x</p><hr>'],
            'before a self-closing hr' => ['x<hr/>', '<p>x</p><hr/>'],
            'before an hr with a space' => ['x<hr />', '<p>x</p><hr />'],
            'an hr between texts' => ['x<hr>y', '<p>x</p><hr><p>y</p>'],
            'a self-closing hr between texts' => ['x<hr/>y', '<p>x</p><hr/><p>y</p>'],
            'an hr with a space between texts' => ['x<hr />y', '<p>x</p><hr /><p>y</p>'],
            'an upper case hr between texts' => ['x<HR>y', '<p>x</p><HR><p>y</p>'],
            'an hr with attributes between texts' => ['x<hr class="a">y', '<p>x</p><hr class="a"><p>y</p>'],
            'an hr with attributes on the next line between texts' => ["x<hr\nclass=\"a\">y", "<p>x</p><hr\nclass=\"a\"><p>y</p>"],
            'an hr and a text after it only' => ['<hr>y', '<hr><p>y</p>'],
            'an hr and a text before it only' => ['y<hr>', '<p>y</p><hr>'],
            'an hr between lines' => ["A\n<hr>\nB", '<p>A</p><hr><p>B</p>'],
            'an hr between paragraphs' => ["A\n\n<hr>\n\nB", "<p>A</p>\n<hr>\n<p>B</p>"],
            'an hr between texts with line breaks' => ["A\nB<hr>C\nD", "<p>A<br>\nB</p><hr><p>C<br>\nD</p>"],
            'two hr and a text between them' => ['<hr>x<hr>y', '<hr><p>x</p><hr><p>y</p>'],
            'an hr between blocks and a text' => ['<p>A</p><hr>B<p>C</p>', '<p>A</p><hr><p>B</p><p>C</p>'],
            'a tag that only starts like an hr' => ['x<hrx>y', '<p>x<hrx>y</p>'],
            'a custom tag that starts like an hr' => ['x<hr-x>y', '<p>x<hr-x>y</p>'],
            'a custom tag that starts like an hr, first' => ['<hr-x>y', '<p><hr-x>y</p>'],
            'a custom tag that starts like an hr, alone' => ['<hr-x>', '<p><hr-x></p>'],
            'a custom tag that starts like an hr, at the end' => ['x<hr-x>', '<p>x<hr-x></p>'],
            'a tag that only starts like an hr, at the end' => ['x<hrx>', '<p>x<hrx></p>'],
            'before a tag with attributes on the next line' => ["x\n<div\nclass=\"a\">y</div>", "<p>x</p><div\nclass=\"a\">y</div>"],
            'after a list' => ['<ul><li>x</li></ul>tail', '<ul><li>x</li></ul><p>tail</p>'],
            'after a list, a blank line between' => ["<ul>\n<li>x</li>\n</ul>\n\ntail", "<ul><li>x</li></ul>\n<p>tail</p>"],
            'inline tags in the text' => ['A <b>B</b> C<p>D</p>', '<p>A <b>B</b> C</p><p>D</p>'],
            'a line break in the text' => ["A\nB<p>C</p>", "<p>A<br>\nB</p><p>C</p>"],
            'blank lines and blocks' => ["A\n\n<p>B</p>\n\nC", "<p>A</p>\n<p>B</p>\n<p>C</p>"],
            'blank lines, a block at the end' => ["A\n\n<p>B</p>\n\n<p>C</p>", "<p>A</p>\n<p>B</p>\n<p>C</p>"],
            'a line break after a block' => ["<p>A</p>\nB", '<p>A</p><p>B</p>'],
            'spaces around the text' => ['<p>A</p>  B  <p>C</p>', '<p>A</p><p>B</p><p>C</p>'],
            'inside a block' => ['<div>A<p>B</p>C</div>', '<div>A<p>B</p><p>C</p></div>'],
            'a closing tag that is not a block' => ['A</span>B<p>C</p>', '<p>A</span>B</p><p>C</p>'],
            'a tag that only starts like a block' => ['A<picture>B</picture>C<p>D</p>', '<p>A<picture>B</picture>C</p><p>D</p>'],
            'a tag that only starts like a block, alone' => ['A<picture>B</picture>', '<p>A<picture>B</picture></p>'],
            'a void tag that is not a block' => ['A<param>B', '<p>A<param>B</p>'],
            'an unclosed pre' => ['x<pre>a', '<p>x</p><pre>a'],
            'a comment and a text side by side' => ["<p>A</p>\n\n<!-- c -->B", "<p>A</p>\n<p><!-- c -->B</p>"],
            'a text that ends with a comment' => ["<p>X</p>\n\nA<!-- c -->", "<p>X</p>\n<p>A<!-- c --></p>"],
            'a text of its own and an empty one between blocks' => ["A\n\n \n\n<p>B</p>", "<p>A</p>\n<p>B</p>"],
            'spaces collapse into one separator' => ["<p>A</p>\n<p>B</p>\n\n\n\n<p>C</p>", "<p>A</p>\n<p>B</p>\n\n<p>C</p>"],
        ];
    }

    #[DataProvider('textAmongBlocks')]
    public function test_a_text_among_blocks_is_wrapped_into_paragraphs(string $html, string $expected): void
    {
        $this->assertSame($expected, (new Content(self::OLD))->html($html));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function blockTags(): array
    {
        $tags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'blockquote', 'figure', 'figcaption', 'pre', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'details', 'summary', 'form', 'fieldset', 'legend', 'address', 'style', 'script'];

        return array_combine($tags, array_map(static fn (string $tag): array => [$tag], $tags));
    }

    #[DataProvider('blockTags')]
    public function test_every_block_tag_closes_the_text_before_it_and_opens_the_text_after_it(string $tag): void
    {
        $this->assertSame(
            '<p>x</p><'.$tag.'>y</'.$tag.'><p>z</p>',
            (new Content(self::OLD))->html('x<'.$tag.'>y</'.$tag.'>z'),
        );
        $this->assertSame(
            '<p>x</p><'.strtoupper($tag).' class="a">y</'.strtoupper($tag).'><p>z</p>',
            (new Content(self::OLD))->html('x<'.strtoupper($tag).' class="a">y</'.strtoupper($tag).'>z'),
        );
    }

    public function test_a_comment_that_is_not_a_block_comment_makes_a_chunk_of_its_own_only_when_alone(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame("<p>A</p>\n<!-- c -->\n<p>B</p>", $content->html("A\n\n<!-- c -->\n\nB"));
        $this->assertSame("<p>A</p>\n<!-- a\nb -->\n<p>B</p>", $content->html("A\n\n<!-- a\nb -->\n\nB"));
        $this->assertSame('<p>A<!-- c -->B</p>', $content->html('A<!-- c -->B'));
    }

    public function test_the_blank_lines_between_chunks_collapse_into_one_line_break(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame("<p>A</p>\n<p>B</p>", $content->html("A\n\n\0\n\nB"));
        $this->assertSame("<p>A</p>\n<p>B</p>", $content->html("A\n\n\0\n\n\0\n\nB"));
        $this->assertSame("<p>A</p>\n<p>B</p>\n<p>C</p>", $content->html("A\n\nB\n\nC"));
    }

    public function test_the_elements_wordpress_leaves_untouched_are_kept_as_they_are(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame("<p>x</p><pre>a\n\nb</pre><p>y</p>", $content->html("x<pre>a\n\nb</pre>y"));
        $this->assertSame("<p>x</p><pre>\n  a  \n</pre><p>y</p>", $content->html("x<pre>\n  a  \n</pre>y"));
        $this->assertSame("<p>x</p><pre class=\"a\">\n<b>a</b>\n\n<p>b</p></pre><p>y</p>", $content->html("x<pre class=\"a\">\n<b>a</b>\n\n<p>b</p></pre>y"));
        $this->assertSame("<p>x</p><PRE>a\n\nb</PRE><p>y</p>", $content->html("x<PRE>a\n\nb</PRE>y"));
        $this->assertSame("<p>x</p><script>if (a<p) {}\n\n</div></script><p>y</p>", $content->html("x<script>if (a<p) {}\n\n</div></script>y"));
        $this->assertSame("<p>x</p><style>a\n\nb</style><p>y</p>", $content->html("x<style>a\n\nb</style>y"));
        $this->assertSame("<p>x</p><textarea>a\n\nb</textarea><p>y</p>", $content->html("x<textarea>a\n\nb</textarea>y"));
        $this->assertSame('<p>A</p><textarea>x</textarea><p>B</p>', $content->html('A <textarea>x</textarea> B'));
        $this->assertSame('<p>x</p><pre>a</pre>', $content->html('x<pre>a</pre>'));
        $this->assertSame('<pre>a</pre><p>y</p>', $content->html('<pre>a</pre>y'));
        $this->assertSame("<p>A</p>\n<pre>a\n\nb</pre>\n<p>B</p>", $content->html("A\n\n<pre>a\n\nb</pre>\n\nB"));
    }

    public function test_each_kept_element_comes_back_in_its_own_place(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>x</p><pre>one</pre><p>y</p><script>two</script><p>z</p>', $content->html('x<pre>one</pre>y<script>two</script>z'));

        // The seventeen of them: an index is more than one digit.
        $html = '';
        $expected = '';

        foreach (range(0, 16) as $number) {
            $html .= 'x<pre>'.$number.'</pre>';
            $expected .= '<p>x</p><pre>'.$number.'</pre>';
        }

        $this->assertSame($expected, $content->html($html));
    }

    public function test_a_kept_element_ends_with_its_own_closing_tag(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame("<p>x</p><script>a</pre>\n\nb</script><p>y</p>", $content->html("x<script>a</pre>\n\nb</script>y"));
        // <preview> is no <pre>, and it does not reach the <pre> that follows it.
        $this->assertSame("<p>x<preview>a</p>\n<p>b</preview></p><pre>c</pre>", $content->html("x<preview>a\n\nb</preview><pre>c</pre>"));
        $this->assertSame("<p>x<styled>a</p>\n<p>b</styled></p><style>c</style>", $content->html("x<styled>a\n\nb</styled><style>c</style>"));
    }

    public function test_a_more_tag_is_a_block_of_its_own(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>Before</p><!--more--><p>after</p>', $content->html('Before <!--more--> after'));
        $this->assertSame('<p>A</p><!--more--><p>B</p>', $content->html('A<!--more-->B'));
        $this->assertSame('<p>A</p><!--more Read on--><p>B</p>', $content->html('A<!--more Read on-->B'));
        $this->assertSame('<p>A</p><!--more--><p>B</p>', $content->html("A\n<!--more-->\nB"));
        $this->assertSame("<p>A</p>\n<!--more-->\n<p>B</p>", $content->html("A\n\n<!--more-->\n\nB"));
        $this->assertSame('<p>A</p><!--MORE--><p>B</p>', $content->html('A<!--MORE-->B'));
        $this->assertSame("<p>A</p><!--more\nRead\non--><p>B</p>", $content->html("A<!--more\nRead\non-->B"));
        $this->assertSame('<p>A</p><!--more--><p>B</p><!--more--><p>C</p>', $content->html('A<!--more-->B<!--more-->C'));
        $this->assertSame('<p>A</p><!--more--><p>B</p>', $content->html('<p>A</p><!--more-->B'));
        $this->assertSame('<!--more--><p>B</p>', $content->html('<!--more-->B'));
        $this->assertSame('<p>A</p><!--more-->', $content->html('A<!--more-->'));
    }

    public function test_a_comment_that_only_starts_like_a_more_tag_is_a_comment(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>A<!--moreover-->B</p>', $content->html('A<!--moreover-->B'));
        $this->assertSame('<p>A<!-- more -->B</p>', $content->html('A<!-- more -->B'));
        $this->assertSame('<!--moreover-->', $content->html('<!--moreover-->'));
    }

    public function test_the_marks_the_paragraphs_use_are_taken_out_of_the_text(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>ab</p>', $content->html("a\x01b"));
        $this->assertSame('<p>ab</p>', $content->html("a\x02b"));
        $this->assertSame('<p>abc</p>', $content->html("a\x01b\x02c"));
        $this->assertSame('<p>a0b</p>', $content->html("a\x01\x020\x02\x01b"));
        $this->assertSame('<pre>P</pre><p>0</p>', $content->html("<pre>P</pre>\x01\x020\x02\x01"));
        $this->assertSame("<pre>P</pre>\n<p>0</p>", $content->html("<pre>P</pre>\n\n\x020\x02"));
        $this->assertSame('<p>A</p><p>B</p>', $content->html("<p>A</p>\x01<p>B</p>"));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function captions(): array
    {
        return [
            'a picture and a text' => ['[caption id="a" align="x"]<img src="i.jpg"/> Text[/caption]', '<figure><img src="i.jpg"/><figcaption>Text</figcaption></figure>'],
            'a picture with a link' => ['[caption]<a href="/x"><img src="i.jpg"></a> Text[/caption]', '<figure><a href="/x"><img src="i.jpg"></a><figcaption>Text</figcaption></figure>'],
            'a link with spaces and line breaks' => ["[caption]<a href=\"/x\">\n <img src=\"i.jpg\">\n </a>\n Text here\n[/caption]", "<figure><a href=\"/x\">\n <img src=\"i.jpg\">\n </a><figcaption>Text here</figcaption></figure>"],
            'a link and a picture in capitals' => ['[caption]<A HREF="/x"><IMG src="i"></A> T[/caption]', '<figure><A HREF="/x"><IMG src="i"></A><figcaption>T</figcaption></figure>'],
            'the caption is an attribute' => ['[caption caption="Attr"]<img src="i.jpg"> Text[/caption]', '<figure><img src="i.jpg"><figcaption>Attr</figcaption></figure>'],
            'the caption is an attribute in single quotes' => ["[caption caption='Attr s']<img src=\"i.jpg\"> Text[/caption]", '<figure><img src="i.jpg"><figcaption>Attr s</figcaption></figure>'],
            'the caption is an attribute in capitals' => ['[caption CAPTION="Up"]<img src="i"> T[/caption]', '<figure><img src="i"><figcaption>Up</figcaption></figure>'],
            'the caption is an attribute with spaces around the sign' => ['[caption caption = "sp"]<img src="i"> T[/caption]', '<figure><img src="i"><figcaption>sp</figcaption></figure>'],
            'the caption is an attribute with an apostrophe' => ['[caption caption="it\'s"]<img src="i">[/caption]', '<figure><img src="i"><figcaption>it\'s</figcaption></figure>'],
            'the caption is an attribute among others' => ['[caption id="attachment_1" width="300" caption="C" align=\'left\']<img src="i"> T[/caption]', '<figure><img src="i"><figcaption>C</figcaption></figure>'],
            'the caption is an attribute, the last one wins' => ['[caption caption="a" caption="b"]<img src="i"> T[/caption]', '<figure><img src="i"><figcaption>b</figcaption></figure>'],
            'the caption is an attribute with spaces around it' => ['[caption caption="  C  "]<img src="i"> T[/caption]', '<figure><img src="i"><figcaption>C</figcaption></figure>'],
            'the caption is an empty attribute' => ['[caption caption=""]<img src="i.jpg"> Text[/caption]', '<figure><img src="i.jpg"></figure>'],
            'the caption is an empty attribute in single quotes' => ["[caption caption='']<img src=\"i.jpg\"> Text[/caption]", '<figure><img src="i.jpg"></figure>'],
            'the caption is an attribute of spaces' => ['[caption caption="  "]<img src="i.jpg"> Text[/caption]', '<figure><img src="i.jpg"></figure>'],
            'a picture alone' => ['[caption]<img src="i.jpg">[/caption]', '<figure><img src="i.jpg"></figure>'],
            'a picture and a space' => ['[caption]<img src="i.jpg"> [/caption]', '<figure><img src="i.jpg"></figure>'],
            'a picture with a link, alone' => ['[caption]<a href="/x"><img src="i.jpg"></a>[/caption]', '<figure><a href="/x"><img src="i.jpg"></a></figure>'],
            'no picture' => ['[caption]Just text[/caption]', '<figure><figcaption>Just text</figcaption></figure>'],
            'no picture, an attribute' => ['[caption caption="A"]Just text[/caption]', '<figure><figcaption>A</figcaption></figure>'],
            'no picture, markup' => ['[caption]<b>Just</b> <i>text</i>[/caption]', '<figure><figcaption><b>Just</b> <i>text</i></figcaption></figure>'],
            'a text before the picture' => ['[caption]Text <img src="i">[/caption]', '<figure><figcaption>Text <img src="i"></figcaption></figure>'],
            'a picture that is no img' => ['[caption]<image src="i"> T[/caption]', '<figure><figcaption><image src="i"> T</figcaption></figure>'],
            'a link that is no a' => ['[caption]<abbr><img src="i"></abbr> T[/caption]', '<figure><figcaption><abbr><img src="i"></abbr> T</figcaption></figure>'],
            'a text of several lines' => ["[caption]\n<img src=\"i.jpg\">\nline one\nline two\n[/caption]", "<figure><img src=\"i.jpg\"><figcaption>line one\nline two</figcaption></figure>"],
            'two pictures' => ['[caption]<img src="1"><img src="2"> T[/caption]', '<figure><img src="1"><figcaption><img src="2"> T</figcaption></figure>'],
            'a link that is not closed' => ['[caption]<a href="/x"><img src="i.jpg"> T[/caption]', '<figure><a href="/x"><img src="i.jpg"><figcaption>T</figcaption></figure>'],
            'a link that is not opened' => ['[caption]<img src="i.jpg"></a> T[/caption]', '<figure><img src="i.jpg"></a><figcaption>T</figcaption></figure>'],
            'an attribute of the picture is not an attribute of the shortcode' => ['[caption id="a"]<img src="i" caption="Z"> T[/caption]', '<figure><img src="i" caption="Z"><figcaption>T</figcaption></figure>'],
            'a shortcode in capitals' => ['[CAPTION]<img src="1"> One[/CAPTION]', '<figure><img src="1"><figcaption>One</figcaption></figure>'],
            'several captions' => ['[caption]<img src="1"> One[/caption][caption]<img src="2"> Two[/caption]', '<figure><img src="1"><figcaption>One</figcaption></figure><figure><img src="2"><figcaption>Two</figcaption></figure>'],
            'several captions and a text between' => ['[caption]<img src="1"> One[/caption] and [caption caption="Two"]<img src="2">[/caption]', '<figure><img src="1"><figcaption>One</figcaption></figure><p>and</p><figure><img src="2"><figcaption>Two</figcaption></figure>'],
            'a caption among paragraphs' => ["A\n\n[caption]<img src=\"i\"> T[/caption]\n\nB", "<p>A</p>\n<figure><img src=\"i\"><figcaption>T</figcaption></figure>\n<p>B</p>"],
        ];
    }

    #[DataProvider('captions')]
    public function test_a_caption_is_a_figure(string $html, string $expected): void
    {
        $this->assertSame($expected, (new Content(self::OLD))->html($html));
        // Whether the galleries are looked at or not, the captions are the same.
        $this->assertSame($expected, (new Content(self::OLD))->html($html, static fn (array $ids): array => []));
    }

    public function test_a_shortcode_that_only_starts_like_a_caption_is_not_one(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>[captions]<img src="1"> One[/captions]</p>', $content->html('[captions]<img src="1"> One[/captions]'));
        $this->assertSame('<p>[caption]<img src="1"> One</p>', $content->html('[caption]<img src="1"> One'));
        $this->assertSame('<p>[caption]<img src="1"> One[/caption</p>', $content->html('[caption]<img src="1"> One[/caption'));
    }

    /**
     * @param  array<int,array{url:string,alt:string}>  $images
     * @param  array<int,array<int,int>>  $received  the IDs of every gallery asked for
     */
    private function galleryHtml(string $html, array $images, array &$received = []): string
    {
        return (new Content(self::OLD))->html($html, static function (array $ids) use ($images, &$received): array {
            $received[] = $ids;

            return $images;
        });
    }

    private const TWO_FIGURES = "<figure><img src=\"/a.jpg\" alt=\"A\"></figure>\n<figure><img src=\"/b.jpg\" alt=\"B\"></figure>";

    /**
     * @return array<string,array{0:string,1:array<int,int>}>
     */
    public static function galleries(): array
    {
        return [
            'ids' => ['[gallery ids="3,4"]', [3, 4]],
            'include' => ['[gallery include="3,4"]', [3, 4]],
            'single quotes' => ["[gallery ids='3,4']", [3, 4]],
            'upper case' => ['[GALLERY IDS="3,4"]', [3, 4]],
            'upper case include' => ['[Gallery Include="3,4"]', [3, 4]],
            'spaces around the sign' => ['[gallery ids = "3,4"]', [3, 4]],
            'other attributes around' => ['[gallery columns="2" link="file" ids="3,4" size=\'thumbnail\']', [3, 4]],
            'ids win over include' => ['[gallery ids="3,4" include="1,2"]', [3, 4]],
            'ids win over include, the other way round' => ['[gallery include="1,2" ids="3,4"]', [3, 4]],
            'empty ids: no include to fall back to' => ['[gallery ids="" include="1,2"]', []],
            'a zero and words are dropped' => ['[gallery ids="0,5,abc,6x,,7,0"]', [5, 6, 7]],
            'spaces around the numbers' => ['[gallery ids=" 3 , 4 "]', [3, 4]],
            'one id' => ['[gallery ids="3"]', [3]],
            'a fraction' => ['[gallery ids="1.5"]', [1]],
            'no ids at all' => ['[gallery]', []],
            'empty ids' => ['[gallery ids=""]', []],
            'empty ids in single quotes' => ["[gallery ids='']", []],
            'ids without quotes are not read' => ['[gallery ids=3,4]', []],
            'other attributes only' => ['[gallery columns="3"]', []],
            'the attributes of the shortcode, not of its name' => ['[gallery="x ids=" ids="3,4"]', []],
            'a closing slash' => ['[gallery ids="3,4" /]', [3, 4]],
        ];
    }

    /**
     * @param  array<int,int>  $ids
     */
    #[DataProvider('galleries')]
    public function test_a_gallery_is_asked_for_its_pictures_by_ids(string $shortcode, array $ids): void
    {
        $received = [];

        $this->assertSame(self::TWO_FIGURES, $this->galleryHtml($shortcode, [['url' => '/a.jpg', 'alt' => 'A'], ['url' => '/b.jpg', 'alt' => 'B']], $received));
        $this->assertSame([$ids], $received);
    }

    public function test_a_gallery_is_a_figure_for_every_picture(): void
    {
        $this->assertSame('<figure><img src="/a.jpg" alt="A"></figure>', $this->galleryHtml('[gallery ids="3"]', [['url' => '/a.jpg', 'alt' => 'A']]));
        $this->assertSame('<figure><img src="/a.jpg" alt=""></figure>', $this->galleryHtml('[gallery ids="3"]', [['url' => '/a.jpg', 'alt' => '']]));
        $this->assertSame(
            "<figure><img src=\"/a.jpg\" alt=\"A\"></figure>\n<figure><img src=\"/b.jpg\" alt=\"B\"></figure>\n<figure><img src=\"/c.jpg\" alt=\"C\"></figure>",
            $this->galleryHtml('[gallery ids="3"]', [['url' => '/a.jpg', 'alt' => 'A'], ['url' => '/b.jpg', 'alt' => 'B'], ['url' => '/c.jpg', 'alt' => 'C']]),
        );
    }

    public function test_the_address_and_the_text_of_a_picture_are_escaped(): void
    {
        $this->assertSame(
            '<figure><img src="/a.jpg?x=1&amp;y=&quot;2&quot;&#039;&lt;" alt="Tom &amp; &quot;Jerry&quot; &#039;s &lt;b&gt;"></figure>',
            $this->galleryHtml('[gallery ids="3"]', [['url' => '/a.jpg?x=1&y="2"\'<', 'alt' => 'Tom & "Jerry" \'s <b>']]),
        );
    }

    public function test_a_gallery_without_pictures_is_gone(): void
    {
        $received = [];

        $this->assertSame('', $this->galleryHtml('[gallery ids="3"]', [], $received));
        $this->assertSame([[3]], $received);
        $this->assertSame('<p>A  B</p>', $this->galleryHtml('A [gallery ids="3"] B', []));
        $this->assertSame("<p>A</p>\n<p>B</p>", $this->galleryHtml("A\n\n[gallery]\n\nB", []));
    }

    public function test_every_gallery_is_asked_for_on_its_own(): void
    {
        $received = [];
        $html = (new Content(self::OLD))->html('[gallery ids="1,2"] and [gallery include="3"] and [gallery]', static function (array $ids) use (&$received): array {
            $received[] = $ids;

            return [['url' => '/'.count($received).'.jpg', 'alt' => '']];
        });

        $this->assertSame('<figure><img src="/1.jpg" alt=""></figure><p>and</p><figure><img src="/2.jpg" alt=""></figure><p>and</p><figure><img src="/3.jpg" alt=""></figure>', $html);
        $this->assertSame([[1, 2], [3], []], $received);
    }

    public function test_a_gallery_among_paragraphs_is_a_block_of_figures(): void
    {
        $this->assertSame(
            "<p>text</p>\n<figure><img src=\"/a.jpg\" alt=\"A\"></figure><figure><img src=\"/b.jpg\" alt=\"B\"></figure>\n<p>more</p>",
            $this->galleryHtml("text\n\n[gallery ids=\"3\"]\n\nmore", [['url' => '/a.jpg', 'alt' => 'A'], ['url' => '/b.jpg', 'alt' => 'B']]),
        );
        $this->assertSame(
            '<p>A [foo bar="1"]x[/foo]</p><figure><img src="/a.jpg" alt="A"></figure>',
            $this->galleryHtml('A [foo bar="1"]x[/foo] [gallery ids="3"]', [['url' => '/a.jpg', 'alt' => 'A']]),
        );
    }

    public function test_a_gallery_stays_as_written_without_a_way_to_find_its_pictures(): void
    {
        $content = new Content(self::OLD);

        $this->assertSame('<p>[gallery ids="3,4"]</p>', $content->html('[gallery ids="3,4"]'));
        $this->assertSame('<p>[gallery ids="3,4"]</p>', $content->html('[gallery ids="3,4"]', null));
        $this->assertSame('<p>[gallery]</p>', $content->html('[gallery]'));
        $this->assertSame('<figure><img src="i"><figcaption>T</figcaption></figure><p>[gallery ids="3"]</p>', $content->html('[caption]<img src="i"> T[/caption][gallery ids="3"]'));
    }

    public function test_a_shortcode_that_only_starts_like_a_gallery_is_not_one(): void
    {
        $received = [];

        $this->assertSame('<p>[galleryx ids="3"]</p>', $this->galleryHtml('[galleryx ids="3"]', [['url' => '/a.jpg', 'alt' => 'A']], $received));
        $this->assertSame('<p>[gallery ids="3"</p>', $this->galleryHtml('[gallery ids="3"', [['url' => '/a.jpg', 'alt' => 'A']], $received));
        $this->assertSame([], $received);
    }

    public function test_other_shortcodes_stay_as_written(): void
    {
        $received = [];
        $html = '[video src="a.mp4"] [embed]x[/embed] [foo bar="1"]x[/foo] [contact-form-7 id="5"]';

        $this->assertSame('<p>'.$html.'</p>', (new Content(self::OLD))->html($html));
        $this->assertSame('<p>'.$html.'</p>', $this->galleryHtml($html, [['url' => '/a.jpg', 'alt' => 'A']], $received));
        $this->assertSame([], $received);
    }

    public function test_the_address_of_a_picture_in_a_gallery_is_not_rewritten(): void
    {
        // The closure gives the addresses of the new site, they are not looked at again.
        $this->assertSame('<figure><img src="/wp-content/uploads/a.jpg" alt=""></figure>', $this->galleryHtml('[gallery ids="3"]', [['url' => '/wp-content/uploads/a.jpg', 'alt' => '']]));
    }

    public function test_the_port_of_the_old_address_is_kept(): void
    {
        $content = new Content('http://localhost:18081');
        $uploads = '/wp-content/uploads/a.jpg';

        $this->assertSame('<img src="'.$uploads.'">', $content->localUploads('<img src="http://localhost:18081/wp-content/uploads/a.jpg">'));
        $this->assertSame('<img src="'.$uploads.'">', $content->localUploads('<img src="https://localhost:18081/wp-content/uploads/a.jpg">'));
        $this->assertSame('<img src="'.$uploads.'">', $content->localUploads('<img src="//localhost:18081/wp-content/uploads/a.jpg">'));
        $this->assertSame('<img src="'.$uploads.'">', $content->localUploads('<img src="http://www.localhost:18081/wp-content/uploads/a.jpg">'));
        $this->assertSame('<img src="'.$uploads.'">', $content->localUploads('<img src="HTTP://LOCALHOST:18081/WP-CONTENT/UPLOADS/a.jpg">'));
    }

    public function test_the_address_with_another_port_or_none_is_not_the_old_one(): void
    {
        $content = new Content('http://localhost:18081');

        foreach (['http://localhost/wp-content/uploads/a.jpg', 'http://localhost:18082/wp-content/uploads/a.jpg', 'http://localhost:180810/wp-content/uploads/a.jpg', 'http://localhost:1808/wp-content/uploads/a.jpg', 'http://localhost:80/wp-content/uploads/a.jpg', '//localhost/wp-content/uploads/a.jpg', 'http://localhost:/wp-content/uploads/a.jpg'] as $url) {
            $this->assertSame('<img src="'.$url.'">', $content->localUploads('<img src="'.$url.'">'), $url);
        }
    }

    public function test_an_address_without_a_port_is_not_the_one_with_a_port(): void
    {
        $content = new Content('http://localhost');

        $this->assertSame('<img src="/wp-content/uploads/a.jpg">', $content->localUploads('<img src="http://localhost/wp-content/uploads/a.jpg">'));
        $this->assertSame('<img src="http://localhost:18081/wp-content/uploads/a.jpg">', $content->localUploads('<img src="http://localhost:18081/wp-content/uploads/a.jpg">'));
    }

    public function test_the_port_stays_when_the_address_has_www(): void
    {
        $content = new Content('https://www.old.example.com:8080/blog');

        $this->assertSame('<img src="/wp-content/uploads/a.jpg"><img src="/wp-content/uploads/b.jpg">', $content->localUploads('<img src="https://www.old.example.com:8080/wp-content/uploads/a.jpg"><img src="//old.example.com:8080/wp-content/uploads/b.jpg">'));
        $this->assertSame('<img src="https://old.example.com/wp-content/uploads/a.jpg">', $content->localUploads('<img src="https://old.example.com/wp-content/uploads/a.jpg">'));
    }

    public function test_only_a_leading_www_is_ignored(): void
    {
        $content = new Content('https://cdn.www.example.com');

        $this->assertSame('<img src="/wp-content/uploads/a.jpg">', $content->localUploads('<img src="https://cdn.www.example.com/wp-content/uploads/a.jpg">'));
        $this->assertSame('<img src="https://cdn.example.com/wp-content/uploads/a.jpg">', $content->localUploads('<img src="https://cdn.example.com/wp-content/uploads/a.jpg">'));
    }

    public function test_addresses_with_and_without_a_port_are_both_rewritten(): void
    {
        $content = new Content('http://localhost:18081', 'https://old.example.com');

        $this->assertSame('<img src="/wp-content/uploads/a.jpg"><img src="/wp-content/uploads/b.jpg"><img src="http://localhost/wp-content/uploads/c.jpg">', $content->localUploads('<img src="http://localhost:18081/wp-content/uploads/a.jpg"><img src="https://old.example.com/wp-content/uploads/b.jpg"><img src="http://localhost/wp-content/uploads/c.jpg">'));
    }

    public function test_the_address_of_a_file_with_a_port_is_a_path_on_the_new_site(): void
    {
        $content = new Content('http://localhost:18081');

        $this->assertSame('/wp-content/uploads/a.jpg', $content->uploadUrl('http://localhost:18081/wp-content/uploads/a.jpg'));
        $this->assertSame('/wp-content/uploads/a.jpg', $content->uploadUrl(' //localhost:18081/wp-content/uploads/a.jpg '));
        $this->assertSame('http://localhost/wp-content/uploads/a.jpg', $content->uploadUrl('http://localhost/wp-content/uploads/a.jpg'));
        $this->assertSame('http://localhost:18082/wp-content/uploads/a.jpg', $content->uploadUrl('http://localhost:18082/wp-content/uploads/a.jpg'));
    }

    public function test_the_port_is_kept_in_a_post_too(): void
    {
        $content = new Content('http://localhost:18081');

        $this->assertSame('<p><img src="/wp-content/uploads/a.jpg"></p>', $content->html('<img src="http://localhost:18081/wp-content/uploads/a.jpg">'));
        $this->assertSame('<p><img src="http://localhost/wp-content/uploads/a.jpg"></p>', $content->html('<img src="http://localhost/wp-content/uploads/a.jpg">'));
        $this->assertSame('<figure><img src="/wp-content/uploads/a.jpg"><figcaption>T</figcaption></figure>', $content->html('[caption]<img src="http://localhost:18081/wp-content/uploads/a.jpg"> T[/caption]'));
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
