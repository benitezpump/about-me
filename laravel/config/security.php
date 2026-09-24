<?php

return [

    /*
    | Qué proxies se creen para leer la IP real del visitante (X-Forwarded-For). Ver App\Support\TrustProxy.
    | Vacío o false: ninguno. Un número: cuántos saltos hay delante (en Render, empieza en 1 y VERIFÍCALO). Una lista de
    | IP/CIDR: solo esos proxies. `true` NO es seguro detrás de un proxy que añade la IP en lugar de reemplazarla.
    */
    'trust_proxy' => env('TRUST_PROXY', ''),

    /*
    | Intentos de login permitidos por IP cada 15 minutos antes de responder 429.
    */
    'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 10),

    /*
    | Vida en segundos del contenido público en caché. Cada edición desde el panel la invalida al instante.
    */
    'content_cache_ttl' => (int) env('CONTENT_CACHE_TTL', 60),

];
