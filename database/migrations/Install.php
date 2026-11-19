<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chisel's run history and the command catalog (signatures captured in a console process).
 */
class Install extends Migration
{
    public function up(): void
    {
        Schema::create('chisel_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            // Craft's users.id is a signed int. Kept (null) when the user is deleted: the audit row survives.
            $table->integer('userId')->nullable()->index();
            $table->foreign('userId')->references('id')->on(Table::USERS)->nullOnDelete();
            $table->string('username')->nullable(); // snapshot, so the audit log reads after a rename/delete
            $table->string('command', 128)->index();
            $table->json('arguments')->nullable(); // display form, sensitive values masked
            $table->text('parameters')->nullable(); // encrypted Artisan parameters; wiped once the run ends
            $table->string('status', 16)->index(); // pending, running, succeeded, failed, refused
            $table->smallInteger('exitCode')->nullable();
            $table->longText('output')->nullable(); // sanitised
            $table->boolean('outputTruncated')->default(false);
            $table->string('error')->nullable(); // why it failed/was refused, never a stack trace
            $table->string('ip', 45)->nullable();
            $table->timestamp('startedAt')->nullable();
            $table->timestamp('finishedAt')->nullable();
            $table->integer('durationMs')->nullable();
            $table->timestamps();
            $table->index(['command', 'created_at']);
        });

        Schema::create('chisel_commands', function (Blueprint $table) {
            $table->string('name', 128)->primary();
            $table->text('description')->nullable();
            $table->json('definition');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chisel_commands');
        Schema::dropIfExists('chisel_runs');
    }
}
