<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// ── Público ─────────────────────────────────────────────────────────────
// Accesibles tanto para visitantes como para usuarios autenticados:
Route::get('/clasificacion', \App\Livewire\Clasificacion::class)->name('clasificacion');
Route::get('/historial',     \App\Livewire\HistorialPartidos::class)->name('historial');

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('clasificacion');
    }

    // A cada rol le lleva a lo primero que sí puede ver: el organizador no
    // tiene acceso a la clasificación, así que entra directo a montar jornada.
    return redirect()->route(Auth::user()->puedeVerPuntuaciones() ? 'clasificacion' : 'jornada.generar');
})->name('inicio');

// ── Invitados ───────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/entrar',   \App\Livewire\Auth\Login::class)->name('login');
    Route::get('/registro', \App\Livewire\Auth\Registro::class)->name('registro');
});

Route::post('/salir', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('clasificacion');
})->name('logout');

// ── Autenticados ────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {

    Route::get('/password', \App\Livewire\Auth\CambiarPassword::class)->name('password.cambiar');

    Route::get('/jornada',             \App\Livewire\GenerarJornada::class)->name('jornada.generar');
    Route::get('/jornada/{jornadaId}', \App\Livewire\GenerarJornada::class)->name('jornada.ver');

    Route::get('/jugadores', \App\Livewire\Jugadores::class)->name('jugadores');
    Route::get('/ajustes',   \App\Livewire\AjustesIA::class)->name('ajustes-ia');

    Route::get('/usuarios',  \App\Livewire\AdminUsuarios::class)->name('usuarios');
    Route::get('/actividad', \App\Livewire\Auditorias::class)->name('auditoria');
});
