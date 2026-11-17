<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Plan;

/**
 * A taxonomy (categories, tags, a custom one) and the generated module its
 * terms become.
 */
final class Taxonomy
{
    /**
     * @param  array<int,string>  $types  post types whose records it is linked to
     */
    public function __construct(
        public readonly string $taxonomy,
        public readonly string $table,
        public readonly string $model,
        public readonly string $prefix,
        public readonly string $label,
        public readonly bool $hierarchical,
        public readonly int $count,
        public readonly array $types = [],
    ) {}

    /**
     * The table linking the records of a module to these terms.
     */
    public function pivot(Type $type): string
    {
        return $type->table.'_'.$this->table;
    }
}
