<?php

namespace App\Services\Facebook;

use RuntimeException;

/**
 * Se lanza cuando Facebook responde "missing_required_variable_value", senal de
 * que el doc_id / las variables de la consulta caducaron. FacebookService la
 * atrapa para disparar el auto-arreglo (sanador) y reintentar.
 */
class StaleGraphqlQueryException extends RuntimeException
{
}
