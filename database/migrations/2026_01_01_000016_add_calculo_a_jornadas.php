<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detalle técnico de cómo el emparejador llegó a la propuesta (pesos,
     * nivel efectivo, coste de cada pista término a término y mejores
     * alternativas). Solo lo ve el admin; sirve para auditar y calibrar.
     */
    public function up(): void
    {
        Schema::table('jornadas', function (Blueprint $table) {
            $table->json('calculo')->nullable()->after('explicacion_ia');
        });
    }

    public function down(): void
    {
        Schema::table('jornadas', function (Blueprint $table) {
            $table->dropColumn('calculo');
        });
    }
};
