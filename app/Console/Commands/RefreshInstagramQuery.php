<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Sanador de Instagram: recaptura el doc_id y las provider variables actuales de
 * la consulta PolarisProfilePostsTabContentQuery_connection y las guarda en
 * storage/app/instagram-graphql.json. InstagramService lo invoca solo cuando
 * detecta que el doc_id caduco.
 *
 * A mano:  php artisan instagram:refresh-query
 */
class RefreshInstagramQuery extends Command
{
    protected $signature = 'instagram:refresh-query {url? : URL del perfil de IG a capturar}';

    protected $description = 'Recaptura el doc_id y las variables actuales de la consulta GraphQL de Instagram';

    public function handle(): int
    {
        $nodeBin = (string) config('services.facebook.node_bin', 'node');
        $url = $this->argument('url') ?: 'https://www.instagram.com/rppnoticias';

        $command = [
            $nodeBin,
            'scripts/capture-graphql-query.mjs',
            'PolarisProfilePostsTabContentQuery_connection',
            'storage/app/instagram-graphql.json',
            $url,
        ];

        $this->info('Ejecutando el sanador de Instagram... esto puede tardar ~20s.');

        $result = Process::path(base_path())->timeout(90)->run($command);
        $this->line(trim($result->output() . $result->errorOutput()));

        if ($result->successful()) {
            $this->info('doc_id/variables de Instagram actualizados correctamente.');
            return self::SUCCESS;
        }

        $this->error('El sanador de Instagram fallo. ¿Navegador 9222 abierto y sesion de IG viva?');
        return self::FAILURE;
    }
}
