<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grupo pequeño, sin necesidad de correo: se entra con un nombre único,
     * no con email. La columna 'email' pasa a opcional y deja de ser única;
     * 'name' pasa a serlo.
     *
     * SQLite no permite modificar una columna existente (ALTER COLUMN) ni
     * quitarle una restricción UNIQUE directamente, así que se reconstruye
     * la tabla entera a mano en vez de confiar en ->change(), que para
     * este tipo de cambio depende de una simulación interna delicada.
     */
    public function up(): void
    {
        Schema::create('users_nuevo', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('rol', 20)->default('jugador');
            $table->foreignId('jugador_id')->nullable()->unique()->constrained('jugadores')->nullOnDelete();
            $table->boolean('activo')->default(false);
            $table->boolean('debe_cambiar_password')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        DB::statement('
            INSERT INTO users_nuevo (
                id, name, email, email_verified_at, password, rol, jugador_id,
                activo, debe_cambiar_password, remember_token, created_at, updated_at
            )
            SELECT
                id, name, email, email_verified_at, password, rol, jugador_id,
                activo, debe_cambiar_password, remember_token, created_at, updated_at
            FROM users
        ');

        Schema::drop('users');
        Schema::rename('users_nuevo', 'users');
    }

    public function down(): void
    {
        Schema::create('users_viejo', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('rol', 20)->default('jugador');
            $table->foreignId('jugador_id')->nullable()->unique()->constrained('jugadores')->nullOnDelete();
            $table->boolean('activo')->default(false);
            $table->boolean('debe_cambiar_password')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        DB::statement('
            INSERT INTO users_viejo (
                id, name, email, email_verified_at, password, rol, jugador_id,
                activo, debe_cambiar_password, remember_token, created_at, updated_at
            )
            SELECT
                id, name, COALESCE(email, name || \'@local.invalido\'), email_verified_at, password, rol, jugador_id,
                activo, debe_cambiar_password, remember_token, created_at, updated_at
            FROM users
        ');

        Schema::drop('users');
        Schema::rename('users_viejo', 'users');
    }
};
