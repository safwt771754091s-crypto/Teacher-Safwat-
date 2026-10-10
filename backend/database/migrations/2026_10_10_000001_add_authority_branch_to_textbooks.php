<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbooks', function (Blueprint $table) {
            // Yemen has two education authorities with distinct editions. Books
            // shared by both are 'common'.
            $table->enum('authority', ['common', 'sanaa', 'aden'])->default('common')->after('publisher');
            // For grades 11–12: general | scientific (علمي) | literary (أدبي).
            $table->enum('branch', ['general', 'scientific', 'literary'])->default('general')->after('authority');
        });
    }

    public function down(): void
    {
        Schema::table('textbooks', function (Blueprint $table) {
            $table->dropColumn(['authority', 'branch']);
        });
    }
};
