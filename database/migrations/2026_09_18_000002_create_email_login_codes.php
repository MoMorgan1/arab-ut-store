<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The email half of a login code, mirroring `phone_verifications`.
     *
     * It exists for the cohort the Salla import left locked out: an account
     * with `password` null and `email_verified_at` null cannot sign in, cannot
     * reset, and until now was told twice that help was on the way. A code to
     * the address on the account both lets them in and proves the address is
     * theirs, which is the same thing verification would have proved.
     *
     * The code itself is never stored - only its hash - so a copy of this
     * table is not a list of live logins.
     */
    public function up(): void
    {
        Schema::create('email_login_codes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_login_codes');
    }
};
