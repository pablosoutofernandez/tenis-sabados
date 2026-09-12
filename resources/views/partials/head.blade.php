<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f172a">
<meta name="description" content="{{ config('tenis.temporada.nombre') }} — clasificación, jornadas y resultados del torneo de dobles.">

<title>{{ config('tenis.temporada.nombre') }}</title>

{{-- Favicon: ahora el logo es un PNG, no un SVG --}}
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">

{{-- Vista previa al compartir el enlace en WhatsApp o similar --}}
<meta property="og:title" content="{{ config('tenis.temporada.nombre') }}">
<meta property="og:description" content="Clasificación, jornadas y resultados del torneo de dobles.">
<meta property="og:image" content="{{ url('/icon-512.png') }}">
<meta property="og:type" content="website">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
