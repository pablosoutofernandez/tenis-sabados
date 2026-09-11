<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notas que deja el organizador al corregir a mano un partido, con el
     * motivo del cambio. Se le pasan a la IA en las próximas jornadas —solo
     * las últimas, para no engordar el prompt— a modo de aprendizaje.
     */
    public function up(): void
    {
        Schema::create('notas_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('jornadas')->cascadeOnDelete();
            $table->foreignId('partido_id')->nullable()->constrained('partidos')->nullOnDelete();
            $table->text('texto');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_ia');
    }
};
