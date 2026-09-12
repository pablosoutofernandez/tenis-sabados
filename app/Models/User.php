<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROL_ADMIN       = 'admin';
    public const ROL_ORGANIZADOR = 'organizador';
    public const ROL_JUGADOR     = 'jugador';

    public const ROLES = [
        self::ROL_ADMIN       => 'Administrador',
        self::ROL_ORGANIZADOR => 'Organizador',
        self::ROL_JUGADOR     => 'Jugador',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'jugador_id',
        'activo',
        'debe_cambiar_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'activo'                => 'boolean',
            'debe_cambiar_password' => 'boolean',
        ];
    }

    /** El jugador del torneo al que corresponde esta cuenta, si se ha vinculado. */
    public function jugador(): BelongsTo
    {
        return $this->belongsTo(Jugador::class);
    }

    public function esAdmin(): bool
    {
        return $this->rol === self::ROL_ADMIN;
    }

    public function esOrganizador(): bool
    {
        return $this->rol === self::ROL_ORGANIZADOR;
    }

    public function esJugador(): bool
    {
        return $this->rol === self::ROL_JUGADOR;
    }

    /**
     * El organizador monta las jornadas "a ciegas": no ve la clasificación
     * ni los puntos de nadie, para que repartir no dependa de quién va
     * ganando. El algoritmo sí los usa por dentro.
     */
    public function puedeVerPuntuaciones(): bool
    {
        return true;
    }

    public function getRolNombreAttribute(): string
    {
        return self::ROLES[$this->rol] ?? $this->rol;
    }
}
