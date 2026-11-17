<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Support;

/**
 * Text from an old database made valid UTF-8. Two accidents are common on
 * sites that moved between hosts: bytes in Windows-1251 (or Windows-1252)
 * saved into a UTF-8 table, and UTF-8 read as Latin-1 and saved again
 * ("ÐŸÑ€Ð¸Ð²ÐµÑ‚" for "Привет").
 */
final class Text
{
    public static function clean(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return self::fromSingleByte($value);
        }

        return self::undoDoubleEncoding($value);
    }

    /**
     * Cyrillic in Windows-1251 puts most of its bytes at 0xC0–0xFF; Western
     * text in Windows-1252 has few of them.
     */
    private static function fromSingleByte(string $value): string
    {
        // Text that is not UTF-8 has at least one byte from the upper half.
        $high = preg_match_all('/[\x80-\xFF]/', $value);
        $letters = preg_match_all('/[\xC0-\xFF]/', $value);

        $encoding = $letters / $high > 0.5 && self::looksCyrillic($value) ? 'Windows-1251' : 'Windows-1252';

        return mb_convert_encoding($value, 'UTF-8', $encoding);
    }

    /**
     * Runs of lowercase Cyrillic letters (0xE0–0xFF in Windows-1251) are what
     * Russian text is made of; Western text has accented letters alone.
     */
    private static function looksCyrillic(string $value): bool
    {
        return preg_match('/[\xC0-\xFF]{3,}/', $value) === 1;
    }

    private static function undoDoubleEncoding(string $value): string
    {
        // Mojibake has characters from the upper half of Latin-1 and nothing
        // a real Latin-1 reading could not have produced.
        if (preg_match('/[\x{00C2}-\x{00C3}\x{00D0}-\x{00D1}][\x{0080}-\x{00BF}\x{0152}-\x{2122}]/u', $value) !== 1) {
            return $value;
        }

        foreach (['ISO-8859-1', 'Windows-1252'] as $encoding) {
            $bytes = mb_convert_encoding($value, $encoding, 'UTF-8');

            if (mb_convert_encoding($bytes, 'UTF-8', $encoding) === $value && mb_check_encoding($bytes, 'UTF-8')) {
                return $bytes;
            }
        }

        return $value;
    }
}
