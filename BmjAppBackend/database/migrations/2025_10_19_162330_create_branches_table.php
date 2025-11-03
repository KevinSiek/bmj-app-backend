<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->timestamps();
        });

        $timestamp = now();
        $defaults = [
            ['name' => 'Jakarta', 'code' => 'JKT', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Semarang', 'code' => 'SMG', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ];

        foreach ($defaults as $branch) {
            DB::table('branches')->updateOrInsert(
                ['name' => $branch['name']],
                $branch
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
