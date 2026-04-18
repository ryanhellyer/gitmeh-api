<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_ip_daily_hits', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45);
            $table->date('day');
            $table->unsignedBigInteger('hit_count');
            $table->timestamps();

            $table->unique(['ip', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_ip_daily_hits');
    }
};
