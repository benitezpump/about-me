<?php

namespace App\Content;

use RuntimeException;

/** Se lanza al exportar cuando no hay perfil: sin él no hay sitio que exportar (y el archivo no se podría importar). */
class NoContentException extends RuntimeException {}
