<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\WordPress\Source;

/**
 * What the importer reads from a WordPress site, in the shape of its own
 * tables: rows of wp_posts, wp_postmeta, wp_terms with wp_term_taxonomy,
 * wp_users. A database copy and a WXR export both answer it.
 */
interface Source
{
    /** Statuses of content that moves: everything but trash, auto-drafts and revisions. */
    public const STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    public function option(string $name): ?string;

    /**
     * Posts of every type in the given statuses, counted by type.
     *
     * @param  array<int,string>  $statuses
     * @return array<string,int>
     */
    public function postTypeCounts(array $statuses = self::STATUSES): array;

    /**
     * @param  array<int,string>  $statuses
     * @return array<int,int>
     */
    public function postIds(string $type, array $statuses = self::STATUSES): array;

    /**
     * post ID => post_parent of the posts of a type (0 for none).
     *
     * @return array<int,int>
     */
    public function parents(string $type): array;

    /**
     * The distinct meta keys of the posts of a type.
     *
     * @return array<int,string>
     */
    public function metaKeys(string $type): array;

    /**
     * Rows of wp_posts by ID.
     *
     * @param  array<int,int>  $ids
     * @return array<int,object>
     */
    public function posts(array $ids): array;

    /**
     * wp_postmeta of these posts: post ID => meta key => raw value.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array<string,string>>
     */
    public function meta(array $ids): array;

    /**
     * Every term with its taxonomy (wp_terms joined with wp_term_taxonomy):
     * term_taxonomy_id, term_id, taxonomy, name, slug, description, parent.
     *
     * @return array<int,object>
     */
    public function terms(): array;

    /**
     * wp_termmeta: term ID => meta key => raw value.
     *
     * @return array<int,array<string,string>>
     */
    public function termMeta(): array;

    /**
     * wp_term_relationships of these objects: object ID => term_taxonomy_ids.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array<int,int>>
     */
    public function relationships(array $ids): array;

    /**
     * Every object linked to a term of one taxonomy: object ID => term_taxonomy_id.
     *
     * @return array<int,int>
     */
    public function objectsInTaxonomy(string $taxonomy): array;

    /**
     * Rows of wp_users by ID with first_name, last_name and description from
     * wp_usermeta. Never the password.
     *
     * @param  array<int,int>  $ids
     * @return array<int,object>
     */
    public function users(array $ids): array;

    /**
     * WPML's icl_translations rows (element_type, element_id, trid,
     * language_code), or null without WPML.
     *
     * @return array<int,object>|null
     */
    public function wpmlTranslations(): ?array;

    /**
     * The address the old site gave the post, when the source knows it (a WXR
     * export does; a database has to work it out from the permalink settings).
     */
    public function permalink(int $id): ?string;
}
