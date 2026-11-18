<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // NOT unique on purpose: Advanced SQL exercise #7 asks us to *find*
            // duplicate customers by email, so the data must be allowed to
            // contain them (think: a legacy import). In a greenfield system you
            // would add a UNIQUE index on lower(email) instead.
            $table->string('email');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
