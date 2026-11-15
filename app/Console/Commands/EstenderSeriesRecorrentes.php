<?php

namespace App\Console\Commands;

use App\Actions\Series\EstenderRecorrentes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('series:estender-recorrentes')]
#[Description('Gera as parcelas das séries recorrentes até 12 meses à frente (RF-052)')]
class EstenderSeriesRecorrentes extends Command
{
    public function handle(EstenderRecorrentes $estenderRecorrentes): int
    {
        $estendidas = $estenderRecorrentes->handle();

        $this->info("Séries estendidas: {$estendidas}.");

        return self::SUCCESS;
    }
}
