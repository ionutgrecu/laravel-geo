<?php

namespace Ionutgrecu\LaravelGeo\Console;

use Illuminate\Console\Command;
use Ionutgrecu\LaravelGeo\Jobs\EnrichAdministrativeEntityBoundaryJob;
use Ionutgrecu\LaravelGeo\Models\GeoAdministrativeEntity;

class EnrichBoundaries extends Command {
    protected $signature   = 'geo:enrich-boundaries
                                {id? : GeoAdministrativeEntity id (e.g. R2202162). If omitted, processes all entities of \-\-type}
                                {--type= : EntityType value (country, state, county, city…) when no id given; defaults to all}
                                {--dispatch : Dispatch the job to the queue instead of running it synchronously}';
    protected $description = 'Run EnrichAdministrativeEntityBoundaryJob. Default: synchronous (no queue, for debugging). Pass --dispatch to queue the job.';

    public function handle(): int {
        $id       = $this->argument('id');
        $type     = $this->option('type');
        $dispatch = (bool)$this->option('dispatch');

        $geoAdministrativeEntityQuery = GeoAdministrativeEntity::query();

        if ($type)
            $geoAdministrativeEntityQuery->where('type', $type);

        if ($id)
            $geoAdministrativeEntityQuery->where('id', $id);

        $bar = $this->output->createProgressBar($geoAdministrativeEntityQuery->count());
        $bar->start();

        foreach ($geoAdministrativeEntityQuery->cursor() as $geoAdministrativeEntity) {
            /** @var GeoAdministrativeEntity $geoAdministrativeEntity */
            $this->info("Enriching {$geoAdministrativeEntity->name} / $geoAdministrativeEntity->id");
            if($dispatch){
                EnrichAdministrativeEntityBoundaryJob::dispatch($geoAdministrativeEntity->id);
            }else {
                $job = new EnrichAdministrativeEntityBoundaryJob($geoAdministrativeEntity->id);
                $job->handle();
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return 0;
    }
}