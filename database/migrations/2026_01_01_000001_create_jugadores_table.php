<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jugadores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60);
            // Nivel de juego 1.0 - 10.0. Lo usa la IA para equilibrar parejas.
            $table->decimal('nivel', 3, 1)->default(5.0);
            // Texto libre: estilo de juego, saque, físico, con quién encaja...
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jugadores');
    }
};
