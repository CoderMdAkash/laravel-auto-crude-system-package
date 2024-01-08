<?php
namespace Akash\LaravelAutoCrude\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class AutoCrudeGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:crude {crudeName}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Laravel Auto Generate Crude System By MVC pattern';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        if ($this->modelCheck($this->argument('crudeName'))) {
            $this->info('Already Exits this crude');
        } else {

            $modelName = $this->argument('crudeName');
    
            // Create Model
            Artisan::call('make:model', ['name' => $modelName]);

            // Create Migration
            Artisan::call('make:migration', [
                'name' => 'create_' . strtolower($modelName) . 's_table',
                '--create' => strtolower($modelName) . 's',
            ]);

            // Create Controller
            Artisan::call('make:controller', [
                'name' => $modelName . 'Controller',
                '--resource' => true,
            ]);

            // Generate views directory and view files
            $viewsPath = resource_path('views/') . strtolower($modelName) . 's';
            File::makeDirectory($viewsPath, 0755, true);

            // Generate view files (index.blade.php, create.blade.php, edit.blade.php, show.blade.php)
            $viewFiles = ['index', 'create', 'edit', 'show'];

            foreach ($viewFiles as $view) {
                File::put($viewsPath . '/' . $view . '.blade.php', $this->generateViewContent($modelName, $view));
            }

            // Create custom routes
            $routeContent = "\nRoute::resource('" . strtolower($modelName) . "s', \App\Http\Controllers\\". $modelName . "Controller::class);";
            File::append(base_path('routes/web.php'), $routeContent);

            // Link controller to views
            $controllerPath = app_path('Http/Controllers/') . $modelName . 'Controller.php';
            $controllerContent = file_get_contents($controllerPath);

            foreach ($viewFiles as $view) {
                $controllerContent = $this->linkViewToController($controllerContent, $view, $modelName);
            }

            file_put_contents($controllerPath, $controllerContent);

            $this->info('CRUD with all resource for ' . $modelName . ' generated successfully.');

        }

    }
    private function modelCheck($modelName){
        return class_exists('App\Models\\' . $modelName);
    }

    protected function generateViewContent($modelName, $view)
    {
        switch ($view) {
            case 'index':
                return 'index';
            case 'create':
                return 'create';
            case 'edit':
                return 'edit';
            case 'show':
                return 'show';
            default:
                return '';
        }
    }
    private function linkViewToController($controllerContent, $view, $modelName)
    {
        $methodName = '';

        switch ($view) {
            case 'index':
                $methodName = 'index';
                break;
            case 'create':
                $methodName = 'create';
                break;
            case 'edit':
                $methodName = 'edit';
                break;
            case 'show':
                $methodName = 'show';
                break;
        }

        // Define the method start and end positions using patterns
        $methodStartPattern = "public function $methodName(";
        $methodEndPattern = '}';

        // Find the start and end positions of the method content
        $methodStartPos = strpos($controllerContent, $methodStartPattern);
        $methodEndPos = strpos($controllerContent, $methodEndPattern, $methodStartPos);

        // Extract the existing method content
        $methodContent = substr($controllerContent, $methodStartPos, $methodEndPos - $methodStartPos + strlen($methodEndPattern));

        // Append view rendering logic to the end of the method content
        $viewRenderingCode = "\n        return view('" . strtolower($modelName) . "s.$view');";
        $updatedMethodContent = rtrim($methodContent, $methodEndPattern) . $viewRenderingCode . "\n    }\n\n    ";

        // Replace the original method content with the updated content in the controller
        return substr_replace($controllerContent, $updatedMethodContent, $methodStartPos, strlen($methodContent));
    }
}