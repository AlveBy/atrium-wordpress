<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Plan;

/**
 * Where one post type goes: the News module, the Pages module, a generated
 * module, or nowhere.
 */
final class Type
{
    public const NEWS = 'news';

    public const PAGES = 'pages';

    public const GENERATE = 'generate';

    public const SKIP = 'skip';

    /**
     * @param  array<int,Field>  $fields
     * @param  array<int,string>  $taxonomies
     */
    public function __construct(
        public readonly string $type,
        public readonly string $target,
        public readonly string $table,
        public readonly string $model,
        public readonly string $prefix,
        public readonly string $label,
        public readonly bool $hierarchical,
        public readonly int $count,
        public readonly array $fields = [],
        public readonly array $taxonomies = [],
        public readonly ?string $reason = null,
    ) {}

    public function moves(): bool
    {
        return $this->target !== self::SKIP;
    }

    public function generated(): bool
    {
        return $this->target === self::GENERATE;
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
