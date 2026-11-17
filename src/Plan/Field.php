<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Plan;

/**
 * A custom field of a post type: an ACF field, or a plain meta key with no
 * definition (the "Custom Fields" box of the classic editor), and the column
 * it becomes.
 */
final class Field
{
    /** Kinds whose text is written per language. */
    public const TRANSLATABLE = ['text', 'textarea', 'wysiwyg', 'meta'];

    /** Kinds kept as JSON: lists and structures. */
    public const JSON = ['checkbox', 'gallery', 'post_object', 'page_link', 'relationship', 'taxonomy', 'user', 'repeater', 'group', 'flexible_content', 'link', 'google_map'];

    /**
     * @param  array<string,string>  $choices  value => label of select, radio and checkbox fields
     * @param  array<int,string>  $subFields  names of the fields of a repeater, group or flexible content
     */
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly string $label,
        public readonly string $kind,
        public readonly array $choices = [],
        public readonly bool $multiple = false,
        public readonly array $subFields = [],
    ) {}

    public function translatable(): bool
    {
        return in_array($this->kind, self::TRANSLATABLE, true);
    }

    public function isJson(): bool
    {
        return in_array($this->kind, self::JSON, true) || ($this->multiple && in_array($this->kind, ['select'], true));
    }

    /**
     * What the column holds: json, text, string, decimal, boolean, date,
     * datetime.
     */
    public function storage(): string
    {
        return match (true) {
            $this->translatable(), $this->isJson() => 'json',
            in_array($this->kind, ['number', 'range'], true) => 'decimal',
            $this->kind === 'true_false' => 'boolean',
            $this->kind === 'date_picker' => 'date',
            $this->kind === 'date_time_picker' => 'datetime',
            in_array($this->kind, ['select', 'radio', 'button_group', 'image', 'file', 'email', 'url', 'color_picker', 'time_picker'], true) => 'string',
            default => 'text',
        };
    }
}
