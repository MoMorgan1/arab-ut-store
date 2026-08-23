<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The currency the customer was shown when the turn was claimed.
     *
     * Service cards read the session's display currency while the assistant's
     * own price table was built with the store default, so a customer browsing
     * in AED could be quoted SAR in the reply and shown AED on the card beside
     * it. The turn is claimed inside a request, where the session is available;
     * the model request is not always, so the currency travels on the turn.
     *
     * Nullable on purpose: turns claimed before this column existed have no
     * recorded currency, and the builder falls back to the store default for
     * them rather than guessing.
     */
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table): void {
            $table->char('display_currency', 3)->nullable()->after('prompt_version');
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table): void {
            $table->dropColumn('display_currency');
        });
    }
};
