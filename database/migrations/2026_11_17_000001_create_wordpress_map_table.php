<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each WordPress post, term, author and file became, and the old
     * addresses that lead to it: a second run updates instead of adding a
     * copy, sync finds what changed, and the 301 map is written from here.
     */
    public function up(): void
    {
        Schema::create('wordpress_map', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->unsignedBigInteger('wp_id');
            $table->string('wp_type', 64);
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            // [[old path, new path], …]
            $table->json('paths')->nullable();
            $table->dateTime('wp_modified')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'wp_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_map');
    }
};
