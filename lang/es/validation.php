<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Líneas de validación
    |--------------------------------------------------------------------------
    |
    | Solo se han traducido las reglas que este proyecto usa de verdad (ver
    | app/Livewire/**), no las ~140 líneas del archivo por defecto de Laravel.
    | Si añades una regla nueva (date, array, in...) y no tiene traducción
    | aquí, cae en el mensaje en inglés de Laravel — añádela entonces.
    |
    */

    'between' => [
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string'  => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean'  => 'El campo :attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'exists'   => 'El :attribute seleccionado no es válido.',
    'integer'  => 'El campo :attribute debe ser un número entero.',
    'max' => [
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string'  => 'El campo :attribute no puede tener más de :max caracteres.',
    ],
    'min' => [
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string'  => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'numeric'  => 'El campo :attribute debe ser un número.',
    'required' => 'El campo :attribute es obligatorio.',
    'string'   => 'El campo :attribute debe ser una cadena de texto.',
    'unique'   => 'Ese :attribute ya está en uso.',

    /*
    |--------------------------------------------------------------------------
    | Nombres de los campos
    |--------------------------------------------------------------------------
    |
    | Para que el mensaje diga "el campo nombre..." y no "el campo name...".
    |
    */

    'attributes' => [
        'name'                   => 'nombre',
        'nombre'                 => 'nombre',
        'password'               => 'contraseña',
        'password_confirmation'  => 'confirmación de la contraseña',
        'actual'                 => 'contraseña actual',
        'nivel'                  => 'nivel',
        'activo'                 => 'activo',
        'sets_a'                 => 'sets del equipo A',
        'sets_b'                 => 'sets del equipo B',
        'retirado_id'            => 'jugador retirado',
    ],

];
