<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Create the sessions table on demand.
 *
 * The suite runs the array session driver, so no sessions table exists and any
 * assertion about session rows silently passes unless one is created first.
 */
function createSessionsTableForTest(): void
{
    if (Schema::hasTable('sessions')) {
        return;
    }

    Schema::create('sessions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->unsignedBigInteger('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });
}
