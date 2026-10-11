<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('student')->index()->after('email');
            $t->string('phone')->nullable()->after('role');
            $t->string('avatar')->nullable()->after('phone');
            $t->enum('status', ['active', 'suspended'])->default('active')->after('avatar');
            $t->string('locale')->default('ar')->after('status');
            $t->timestamp('last_login_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['role', 'phone', 'avatar', 'status', 'locale', 'last_login_at']);
        });
    }
};
