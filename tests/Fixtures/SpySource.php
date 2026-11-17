<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Tests\Fixtures;

use Atrium\WordPress\Plan\Plan;
use Atrium\WordPress\Source\Source;

/**
 * A source that answers as the one it wraps does and remembers what it was
 * asked, for tests of how often and with what a class reads.
 */
final class SpySource implements Source
{
    /** @var array<string,array<int,array<int,mixed>>> method => the arguments of each call */
    public array $calls = [];

    public function __construct(private readonly Source $inner) {}

    /**
     * The plan with its source replaced by a spy.
     */
    public static function watch(Plan $plan): Plan
    {
        return new Plan(new self($plan->source), $plan->languages, $plan->types, $plan->taxonomies, $plan->authorsTable, $plan->authorsModel);
    }

    public function option(string $name): ?string
    {
        $this->calls[__FUNCTION__][] = [$name];

        return $this->inner->option($name);
    }

    public function postTypeCounts(array $statuses = self::STATUSES): array
    {
        $this->calls[__FUNCTION__][] = [$statuses];

        return $this->inner->postTypeCounts($statuses);
    }

    public function postIds(string $type, array $statuses = self::STATUSES): array
    {
        $this->calls[__FUNCTION__][] = [$type, $statuses];

        return $this->inner->postIds($type, $statuses);
    }

    public function parents(string $type): array
    {
        $this->calls[__FUNCTION__][] = [$type];

        return $this->inner->parents($type);
    }

    public function metaKeys(string $type): array
    {
        $this->calls[__FUNCTION__][] = [$type];

        return $this->inner->metaKeys($type);
    }

    public function posts(array $ids): array
    {
        $this->calls[__FUNCTION__][] = [$ids];

        return $this->inner->posts($ids);
    }

    public function meta(array $ids): array
    {
        $this->calls[__FUNCTION__][] = [$ids];

        return $this->inner->meta($ids);
    }

    public function terms(): array
    {
        $this->calls[__FUNCTION__][] = [];

        return $this->inner->terms();
    }

    public function termMeta(): array
    {
        $this->calls[__FUNCTION__][] = [];

        return $this->inner->termMeta();
    }

    public function relationships(array $ids): array
    {
        $this->calls[__FUNCTION__][] = [$ids];

        return $this->inner->relationships($ids);
    }

    public function objectsInTaxonomy(string $taxonomy): array
    {
        $this->calls[__FUNCTION__][] = [$taxonomy];

        return $this->inner->objectsInTaxonomy($taxonomy);
    }

    public function users(array $ids): array
    {
        $this->calls[__FUNCTION__][] = [$ids];

        return $this->inner->users($ids);
    }

    public function wpmlTranslations(): ?array
    {
        $this->calls[__FUNCTION__][] = [];

        return $this->inner->wpmlTranslations();
    }

    public function permalink(int $id): ?string
    {
        $this->calls[__FUNCTION__][] = [$id];

        return $this->inner->permalink($id);
    }
}
