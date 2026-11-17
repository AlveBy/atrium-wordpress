<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Support;

/**
 * PHP-serialized values from wp_postmeta and wp_options, read safely: no
 * objects are ever made (allowed_classes false), and a value broken by a
 * search-and-replace of the domain (a string whose s:N length no longer
 * matches) is repaired by counting the lengths again.
 */
final class Serialized
{
    /**
     * Whether the text looks like a serialized value at all.
     */
    public static function looksSerialized(string $value): bool
    {
        return $value === 'N;' || preg_match('/^(a:\d+:\{|s:\d+:"|i:-?\d+;|d:[\d.eE+-]+;|b:[01];|O:\d+:")/', $value) === 1;
    }

    /**
     * The value, decoded where it is serialized and readable; the text as it
     * is otherwise.
     */
    public static function decode(?string $value): mixed
    {
        if ($value === null || ! self::looksSerialized($value)) {
            return $value;
        }

        [$ok, $decoded] = self::attempt($value);

        if ($ok) {
            return $decoded;
        }

        [$ok, $decoded] = self::attempt(self::repair($value));

        return $ok ? $decoded : $value;
    }

    /**
     * Whether a serialized value cannot be read even after the repair.
     */
    public static function isBroken(?string $value): bool
    {
        return $value !== null && self::looksSerialized($value) && self::decode($value) === $value;
    }

    /**
     * Whether a serialized value could only be read after its lengths were
     * counted again: a search and replace on the database broke it.
     */
    public static function wasRepaired(?string $value): bool
    {
        return $value !== null && self::looksSerialized($value) && ! self::attempt($value)[0] && self::decode($value) !== $value;
    }

    /**
     * The value as an array: a serialized array decoded, anything else in a
     * list of its own, nothing for an empty value.
     *
     * @return array<array-key,mixed>
     */
    public static function toArray(?string $value): array
    {
        $decoded = self::decode($value);

        if (is_array($decoded)) {
            return $decoded;
        }

        return $decoded === null || $decoded === '' ? [] : [$decoded];
    }

    /**
     * Every s:N:"…" with its length counted again.
     */
    public static function repair(string $value): string
    {
        return (string) preg_replace_callback(
            '/s:\d+:"(.*?)";(?=[}a-zA-Z]|$)/s',
            static fn (array $match): string => 's:'.strlen($match[1]).':"'.$match[1].'";',
            $value,
        );
    }

    /**
     * @return array{0:bool,1:mixed}
     */
    private static function attempt(string $value): array
    {
        // A text that is not a serialized value gives false, with a notice that is not wanted here.
        $decoded = @unserialize($value, ['allowed_classes' => false]);

        return [$decoded !== false || $value === 'b:0;', $decoded];
    }
}
