<?php

namespace App\Services\Instagram;

use RuntimeException;

/**
 * Se lanza cuando Instagram responde sin "data", senal de que el doc_id / las
 * variables de la consulta caducaron. InstagramService la atrapa para disparar
 * el auto-arreglo (sanador) y reintentar.
 */
class StaleGraphqlQueryException extends RuntimeException
{
}
