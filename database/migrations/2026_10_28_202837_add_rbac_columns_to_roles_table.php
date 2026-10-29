<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonnes ajoutées au modèle App\Models\Role (au-dessus de la table Spatie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('code')->nullable()->index()->after('guard_name');
            $table->text('description')->nullable()->after('code');
            $table->boolean('is_system')->default(false)->after('description');
            $table->integer('level')->default(0)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'code', 'description', 'is_system', 'level']);
        });
    }
};
