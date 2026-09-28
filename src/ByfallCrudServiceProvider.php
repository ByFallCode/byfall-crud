<?php

namespace ByfallCode\ByfallCrud;

use ByfallCode\ByfallCrud\Console\Commands\DeleteEntity;
use ByfallCode\ByfallCrud\Console\Commands\MakeApiCollection;
use ByfallCode\ByfallCrud\Console\Commands\MakeEntity;
use ByfallCode\ByfallCrud\Console\Commands\Install;
use Illuminate\Support\ServiceProvider;

class ByfallCrudServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeEntity::class,
                DeleteEntity::class,
                MakeApiCollection::class,
                Install::class,
            ]);
        }
    }
}
