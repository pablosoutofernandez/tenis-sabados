<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Limpieza tras pasar el reparto de un prompt de IA a un algoritmo
     * determinista: la descripción de cada jugador y las notas (generales
     * y por corrección) se escribieron pensando en alimentar ese prompt.
     * El algoritmo no lee texto libre, así que ya no tienen ningún efecto
     * y solo quedaban como campos muertos en el formulario.
     */
    public function up(): void
    {
        Schema::table('jugadores', function (Blueprint $table) {
            $table->dropColumn('descripcion');
        });

        Schema::table('ajustes_ia', function (Blueprint $table) {
            $table->dropColumn('notas');
        });

        Schema::dropIfExists('notas_ia');
    }

    public function down(): void
    {
        Schema::table('jugadores', function (Blueprint $table) {
            $table->text('descripcion')->nullable();
        });

        Schema::table('ajustes_ia', function (Blueprint $table) {
            $table->text('notas')->nullable();
        });

        Schema::create('notas_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('jornadas')->cascadeOnDelete();
            $table->foreignId('partido_id')->nullable()->constrained('partidos')->nullOnDelete();
            $table->text('texto');
            $table->timestamps();
        });
    }
};
