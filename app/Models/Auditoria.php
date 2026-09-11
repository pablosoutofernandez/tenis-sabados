<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Auditoria extends Model
{
    protected $table = 'auditorias';

    public $timestamps = false;

    protected $fillable = ['user_id', 'user_nombre', 'accion', 'detalle', 'jornada_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(Jornada::class);
    }

    /** Deja constancia de quién ha hecho qué. */
    public static function registrar(string $accion, string $detalle, ?int $jornadaId = null): void
    {
        $usuario = Auth::user();

        static::create([
            'user_id'     => $usuario?->id,
            'user_nombre' => $usuario?->name ?? 'sistema',
            'accion'      => $accion,
            'detalle'     => $detalle,
            'jornada_id'  => $jornadaId,
            'created_at'  => now(),
        ]);
    }

    public function getEtiquetaAccionAttribute(): string
    {
        return match ($this->accion) {
            'resultado.guardado'  => 'Resultado guardado',
            'resultado.borrado'   => 'Resultado borrado',
            'partido.corregido'   => 'Partido corregido',
            'jornada.generada'    => 'Jornada generada',
            'jornada.publicada'   => 'Jornada publicada',
            'jornada.eliminada'   => 'Jornada eliminada',
            'jugador.creado'      => 'Jugador creado',
            'jugador.editado'     => 'Jugador editado',
            'jugador.eliminado'   => 'Jugador eliminado',
            'puntos.ajustados'    => 'Puntuación ajustada',
            'ajustes.guardados'   => 'Ajustes guardados',
            'usuario.actualizado' => 'Usuario actualizado',
            'usuario.eliminado'   => 'Usuario eliminado',
            default               => $this->accion,
        };
    }

    /** Color del punto según si la acción toca resultados, jornadas o cuentas. */
    public function getColorAttribute(): string
    {
        return match (true) {
            str_starts_with($this->accion, 'resultado'), str_starts_with($this->accion, 'partido') => 'bg-brand-400',
            str_starts_with($this->accion, 'jornada') => 'bg-ball-400',
            str_starts_with($this->accion, 'usuario') => 'bg-rose-400',
            default => 'bg-cream-300',
        };
    }
}
