<?php

namespace Akash\LaravelAutoCrude;
use Illuminate\Support\ServiceProvider;
use Akash\LaravelAutoCrude\Commands\AutoCrudeGenerateCommand;

class AutoCrudeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //                
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->commands([
            AutoCrudeGenerateCommand::class,
        ]);
    }
}
