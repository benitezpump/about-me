<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#0e1320">
<script src="/static/js/theme-init.js"></script>
<title>{{ $profile->siteTitle }}</title>
<meta name="description" content="{{ $profile->metaDescription }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $profile->siteTitle }}">
<meta property="og:description" content="{{ $profile->ogDescription }}">
<meta property="og:locale" content="es_MX">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400..900&family=JetBrains+Mono:wght@400..600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/static/css/site.css?v={{ substr(md5_file(public_path('static/css/site.css')), 0, 8) }}">
</head>
<body>
@yield('body')
<script src="/static/js/site.js?v={{ substr(md5_file(public_path('static/js/site.js')), 0, 8) }}" defer></script>
</body>
</html>
