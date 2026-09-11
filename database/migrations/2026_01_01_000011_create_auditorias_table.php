<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de quién hace qué: resultados, correcciones de partidos,
     * cambios de nivel, ajustes... Solo lo ve el admin.
     *
     * Se guarda el nombre del usuario además del id: si la cuenta se borra
     * más adelante, el registro sigue diciendo quién fue.
     */
    public function up(): void
    {
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_nombre', 60);
            $table->string('accion', 40);          // resultado.guardado, partido.corregido...
            $table->text('detalle');
            $table->foreignId('jornada_id')->nullable()->constrained('jornadas')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('accion');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
