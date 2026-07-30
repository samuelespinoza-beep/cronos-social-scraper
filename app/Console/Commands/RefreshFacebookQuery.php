<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Ejecuta el "sanador": lanza Chrome headless (scripts/capture-fb-query.mjs),
 * captura la consulta GraphQL actual de Facebook y guarda doc_id + provider
 * variables frescas en storage/app/facebook-graphql.json.
 *
 * Se puede correr a mano:   php artisan facebook:refresh-query
 * Y FacebookService lo invoca solo cuando detecta que el doc_id caduco.
 */
class RefreshFacebookQuery extends Command
{
    protected $signature = 'facebook:refresh-query {url? : URL de la Pagina de FB a capturar}';

    protected $description = 'Recaptura el doc_id y las variables actuales de la consulta GraphQL de Facebook';

    public function handle(): int
    {
        $nodeBin = (string) config('services.facebook.node_bin', 'node');
        $url = $this->argument('url') ?: 'https://www.facebook.com/rppnoticias';

        $command = [
            $nodeBin,
            'scripts/capture-graphql-query.mjs',
            'ProfileCometTimelineFeedRefetchQuery',
            'storage/app/facebook-graphql.json',
            $url,
        ];

        $this->info('Ejecutando el sanador... esto puede tardar ~20s.');

        $result = Process::path(base_path())
            ->timeout(90)
            ->run($command);

        $this->line(trim($result->output() . $result->errorOutput()));

        if ($result->successful()) {
            $this->info('doc_id/variables de Facebook actualizados correctamente.');
            return self::SUCCESS;
        }

        $this->error('El sanador fallo. Revisa que Chrome este instalado y la sesion de FB siga valida.');
        return self::FAILURE;
    }
}
