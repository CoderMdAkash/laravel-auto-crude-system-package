<?php
namespace Akash\LaravelAutoCrude\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Akash\LaravelAutoCrude\Support\ModelSchemaIntrospector;

class AutoCrudeGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:crude
                            {crudeName : Model name, example Post}
                            {--table= : Database table name}
                            {--force : Overwrite generated files}';
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

        $modelName = Str::studly(trim($this->argument('crudeName')));
        $tableName = $this->option('table') ?: Str::snake(Str::pluralStudly($modelName));
        $resourceName = Str::snake(Str::pluralStudly($modelName));
        $resourceView = Str::kebab(Str::pluralStudly($modelName));
        $force = (bool) $this->option('force');

        $modelClass = 'App\\Models\\' . $modelName;

        if (! class_exists($modelClass)) {
            Artisan::call('make:model', ['name' => $modelName]);
            $this->line(Artisan::output());
        }

        $inspector = new ModelSchemaIntrospector($this);
        $meta = $inspector->inspect($modelClass, $tableName);

        $this->generateCrudFiles($modelName, $resourceName, $resourceView, $meta, $force);
        $this->appendRoutes($modelName, $resourceName);

        $this->info('Advanced CRUD scaffold generated for ' . $modelName . '.');
    }

    private function generateCrudFiles(string $modelName, string $resourceName, string $resourceView, array $meta, bool $force): void
    {
        $controllerPath = app_path('Http/Controllers/' . $modelName . 'Controller.php');
        $storeRequestPath = app_path('Http/Requests/Store' . $modelName . 'Request.php');
        $updateRequestPath = app_path('Http/Requests/Update' . $modelName . 'Request.php');
        $configPath = config_path('auto-crude/' . $resourceName . '.php');
        $viewsPath = resource_path('views/' . $resourceView);

        File::ensureDirectoryExists(dirname($controllerPath));
        File::ensureDirectoryExists(dirname($storeRequestPath));
        File::ensureDirectoryExists(dirname($updateRequestPath));
        File::ensureDirectoryExists(dirname($configPath));
        File::ensureDirectoryExists($viewsPath);

        $this->writeIfAllowed($controllerPath, $this->buildController($modelName, $resourceView, $meta), $force);
        $this->writeIfAllowed($storeRequestPath, $this->buildRequest($modelName, 'Store', $meta), $force);
        $this->writeIfAllowed($updateRequestPath, $this->buildRequest($modelName, 'Update', $meta), $force);
        $this->writeIfAllowed($configPath, $this->buildFieldConfig($resourceName, $meta), $force);

        $views = [
            'index.blade.php' => $this->buildIndexView($modelName, $resourceView),
            'create.blade.php' => $this->buildCreateView($modelName, $resourceView),
            'edit.blade.php' => $this->buildEditView($modelName, $resourceView),
            'show.blade.php' => $this->buildShowView($modelName, $resourceView),
            '_form.blade.php' => $this->buildFormView(),
            'field-config.blade.php' => $this->buildFieldConfigView($modelName, $resourceView),
        ];

        foreach ($views as $file => $content) {
            $this->writeIfAllowed($viewsPath . '/' . $file, $content, $force);
        }
    }

    private function writeIfAllowed(string $path, string $content, bool $force): void
    {
        if (File::exists($path) && ! $force) {
            $this->warn('Skipped existing file: ' . $path . ' (use --force to overwrite)');
            return;
        }

        File::put($path, $content);
        $this->line('Generated: ' . $path);
    }

    private function appendRoutes(string $modelName, string $resourceName): void
    {
        $routesFile = base_path('routes/web.php');
        $needle = "Route::resource('" . $resourceName . "'";

        if (! File::exists($routesFile)) {
            return;
        }

        $current = File::get($routesFile);

        if (Str::contains($current, $needle)) {
            $this->warn('Routes already exist in routes/web.php');
            return;
        }

        $routeContent = "\n\nuse App\\Http\\Controllers\\" . $modelName . "Controller;\n" .
            "Route::resource('" . $resourceName . "', " . $modelName . "Controller::class);\n" .
            "Route::post('" . $resourceName . "/bulk', [" . $modelName . "Controller::class, 'bulk'])->name('" . $resourceName . ".bulk');\n" .
            "Route::get('" . $resourceName . "/field-config', [" . $modelName . "Controller::class, 'fieldConfig'])->name('" . $resourceName . ".field-config');\n" .
            "Route::post('" . $resourceName . "/field-config', [" . $modelName . "Controller::class, 'updateFieldConfig'])->name('" . $resourceName . ".field-config.update');\n";

        File::append($routesFile, $routeContent);
        $this->line('Appended routes in routes/web.php');
    }

    private function buildRequest(string $modelName, string $type, array $meta): string
    {
        $className = $type . $modelName . 'Request';
        $rulesExport = var_export($meta['validation_rules'], true);

        return <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$className} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return {$rulesExport};
    }
}
PHP;
    }

    private function buildFieldConfig(string $resourceName, array $meta): string
    {
        $config = [
            'resource' => $resourceName,
            'searchable' => $meta['searchable'],
            'sortable' => $meta['sortable'],
            'default_sort' => $meta['default_sort'],
            'per_page' => 15,
            'fields' => $meta['form_fields'],
        ];

        $configExport = var_export($config, true);
        return <<<PHP
<?php

return {$configExport};
PHP;
    }

    private function buildController(string $modelName, string $resourceView, array $meta): string
    {
        $modelVariable = Str::camel($modelName);
        $hasSoftDelete = $meta['soft_deletes'] ? 'true' : 'false';
        $searchableExport = var_export($meta['searchable'], true);
        $sortableExport = var_export($meta['sortable'], true);
        $enumFiltersExport = var_export($meta['enum_filters'], true);
        $booleanFiltersExport = var_export($meta['boolean_filters'], true);
        $dateFiltersExport = var_export($meta['date_filters'], true);
        $relationFiltersExport = var_export($meta['relation_filters'], true);
        $resourceName = Str::snake(Str::pluralStudly($modelName));

        return <<<PHP
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Store{$modelName}Request;
use App\Http\Requests\Update{$modelName}Request;
use App\Models\\{$modelName};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class {$modelName}Controller extends Controller
{
    private string \$resource = '{$resourceName}';
    private string \$viewPath = '{$resourceView}';
    private bool \$softDeleteEnabled = {$hasSoftDelete};
    private array \$searchable = {$searchableExport};
    private array \$sortable = {$sortableExport};
    private array \$enumFilters = {$enumFiltersExport};
    private array \$booleanFilters = {$booleanFiltersExport};
    private array \$dateFilters = {$dateFiltersExport};
    private array \$relationFilters = {$relationFiltersExport};

    public function index(Request \$request)
    {
        \$query = {$modelName}::query();
        \$fields = \$this->resolvedFields();

        if (\$this->softDeleteEnabled) {
            if (\$request->string('trashed')->toString() === 'with') {
                \$query->withTrashed();
            }

            if (\$request->string('trashed')->toString() === 'only') {
                \$query->onlyTrashed();
            }
        }

        if (\$search = trim((string) \$request->input('q', ''))) {
            \$query->where(function (\$builder) use (\$search) {
                foreach (\$this->searchable as \$column) {
                    \$builder->orWhere(\$column, 'like', '%' . \$search . '%');
                }
            });
        }

        foreach (\$this->enumFilters as \$column) {
            if (\$request->filled(\$column)) {
                \$query->where(\$column, \$request->input(\$column));
            }
        }

        foreach (\$this->booleanFilters as \$column) {
            if (\$request->filled(\$column)) {
                \$query->where(\$column, (bool) \$request->boolean(\$column));
            }
        }

        foreach (\$this->relationFilters as \$column) {
            if (\$request->filled(\$column)) {
                \$query->where(\$column, \$request->input(\$column));
            }
        }

        foreach (\$this->dateFilters as \$column) {
            \$from = \$request->input(\$column . '_from');
            \$to = \$request->input(\$column . '_to');

            if (\$from) {
                \$query->whereDate(\$column, '>=', \$from);
            }

            if (\$to) {
                \$query->whereDate(\$column, '<=', \$to);
            }
        }

        \$sortBy = \$request->input('sort_by', 'id');
        \$direction = \$request->input('sort_direction', 'desc');
        \$direction = \$direction === 'asc' ? 'asc' : 'desc';

        if (! in_array(\$sortBy, \$this->sortable, true)) {
            \$sortBy = \$this->sortable[0] ?? 'id';
        }

        \$perPage = (int) \$request->input('per_page', 15);
        \$perPage = \$perPage > 0 ? min(\$perPage, 100) : 15;

        \$items = \$query->orderBy(\$sortBy, \$direction)
            ->paginate(\$perPage)
            ->withQueryString();

        return view(\$this->viewPath . '.index', [
            'items' => \$items,
            'fields' => \$fields,
            'searchable' => \$this->searchable,
            'sortable' => \$this->sortable,
            'enumFilters' => \$this->enumFilters,
            'booleanFilters' => \$this->booleanFilters,
            'dateFilters' => \$this->dateFilters,
            'relationFilters' => \$this->relationFilters,
            'softDeleteEnabled' => \$this->softDeleteEnabled,
        ]);
    }

    public function create()
    {
        return view(\$this->viewPath . '.create', [
            'fields' => \$this->resolvedFields(),
            'record' => null,
        ]);
    }

    public function store(Store{$modelName}Request \$request)
    {
        {$modelName}::create(\$this->payload(\$request));
        return redirect()->route(\$this->resource . '.index')->with('success', '{$modelName} created successfully.');
    }

    public function show({$modelName} \${$modelVariable})
    {
        return view(\$this->viewPath . '.show', [
            'record' => \${$modelVariable},
            'fields' => \$this->resolvedFields(),
        ]);
    }

    public function edit({$modelName} \${$modelVariable})
    {
        return view(\$this->viewPath . '.edit', [
            'record' => \${$modelVariable},
            'fields' => \$this->resolvedFields(),
        ]);
    }

    public function update(Update{$modelName}Request \$request, {$modelName} \${$modelVariable})
    {
        \${$modelVariable}->update(\$this->payload(\$request));
        return redirect()->route(\$this->resource . '.index')->with('success', '{$modelName} updated successfully.');
    }

    public function destroy({$modelName} \${$modelVariable})
    {
        \${$modelVariable}->delete();
        return redirect()->route(\$this->resource . '.index')->with('success', '{$modelName} deleted successfully.');
    }

    public function bulk(Request \$request)
    {
        \$data = \$request->validate([
            'action' => ['required', 'in:delete,restore,force_delete,update'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
            'field' => ['nullable', 'string'],
            'value' => ['nullable'],
        ]);

        \$query = {$modelName}::query();
        if (\$this->softDeleteEnabled) {
            \$query->withTrashed();
        }
        \$query->whereIn('id', \$data['ids']);

        if (\$data['action'] === 'delete') {
            \$query->get()->each->delete();
        }

        if (\$data['action'] === 'restore' && \$this->softDeleteEnabled) {
            \$query->restore();
        }

        if (\$data['action'] === 'force_delete' && \$this->softDeleteEnabled) {
            \$query->forceDelete();
        }

        if (\$data['action'] === 'update' && ! empty(\$data['field'])) {
            \$query->update([\$data['field'] => \$data['value']]);
        }

        return redirect()->route(\$this->resource . '.index')->with('success', 'Bulk action executed.');
    }

    public function fieldConfig()
    {
        return view(\$this->viewPath . '.field-config', [
            'fields' => \$this->resolvedFields(),
        ]);
    }

    public function updateFieldConfig(Request \$request)
    {
        \$fields = \$request->validate([
            'fields' => ['required', 'array'],
        ])['fields'];

        Storage::disk('local')->put(
            'auto-crude/' . \$this->resource . '_fields.json',
            json_encode(\$fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return redirect()->route(\$this->resource . '.field-config')->with('success', 'Field config updated.');
    }

    private function resolvedFields(): array
    {
        \$base = config('auto-crude.' . \$this->resource . '.fields', []);
        \$overridePath = 'auto-crude/' . \$this->resource . '_fields.json';

        if (Storage::disk('local')->exists(\$overridePath)) {
            \$decoded = json_decode(Storage::disk('local')->get(\$overridePath), true);
            if (is_array(\$decoded)) {
                return \$decoded;
            }
        }

        return \$base;
    }

    private function payload(Request \$request): array
    {
        \$fields = collect(\$this->resolvedFields());
        \$fillable = \$fields->pluck('name')->all();
        \$payload = \$request->only(\$fillable);

        foreach (\$fields as \$field) {
            if ((\$field['input'] ?? '') === 'file' && \$request->hasFile(\$field['name'])) {
                \$payload[\$field['name']] = \$request->file(\$field['name'])->store(\$this->resource, 'public');
            }
        }

        return \$payload;
    }
}
PHP;
    }

    private function buildIndexView(string $modelName, string $resourceView): string
    {
        $route = Str::snake(Str::pluralStudly($modelName));
        return <<<BLADE
<h1>{$modelName} List</h1>

@if (session('success'))
    <p>{{ session('success') }}</p>
@endif

<form method="GET" action="{{ route('{$route}.index') }}">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Search...">

    @foreach (\$enumFilters as \$column)
        <input type="text" name="{{ \$column }}" value="{{ request(\$column) }}" placeholder="Filter {{ \$column }}">
    @endforeach

    @foreach (\$booleanFilters as \$column)
        <select name="{{ \$column }}">
            <option value="">All {{ \$column }}</option>
            <option value="1" @selected(request(\$column) === '1')>Yes</option>
            <option value="0" @selected(request(\$column) === '0')>No</option>
        </select>
    @endforeach

    @foreach (\$dateFilters as \$column)
        <input type="date" name="{{ \$column }}_from" value="{{ request(\$column . '_from') }}">
        <input type="date" name="{{ \$column }}_to" value="{{ request(\$column . '_to') }}">
    @endforeach

    <select name="sort_by">
        @foreach (\$sortable as \$column)
            <option value="{{ \$column }}" @selected(request('sort_by', 'id') === \$column)>{{ \$column }}</option>
        @endforeach
    </select>
    <select name="sort_direction">
        <option value="desc" @selected(request('sort_direction', 'desc') === 'desc')>Desc</option>
        <option value="asc" @selected(request('sort_direction') === 'asc')>Asc</option>
    </select>
    <button type="submit">Apply</button>
</form>

<form method="POST" action="{{ route('{$route}.bulk') }}">
    @csrf
    <div style="margin: 12px 0;">
        <select name="action" required>
            <option value="delete">Bulk Delete</option>
            <option value="update">Bulk Update</option>
            @if (\$softDeleteEnabled)
                <option value="restore">Bulk Restore</option>
                <option value="force_delete">Bulk Force Delete</option>
            @endif
        </select>
        <input type="text" name="field" placeholder="Field for update">
        <input type="text" name="value" placeholder="Value for update">
        <button type="submit">Run</button>
    </div>

    <table border="1" cellpadding="8" cellspacing="0">
        <thead>
            <tr>
                <th>Select</th>
                @foreach (\$fields as \$field)
                    <th>{{ \$field['label'] ?? \$field['name'] }}</th>
                @endforeach
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse (\$items as \$item)
                <tr>
                    <td><input type="checkbox" name="ids[]" value="{{ \$item->id }}"></td>
                    @foreach (\$fields as \$field)
                        <td>{{ \$item->{\$field['name']} }}</td>
                    @endforeach
                    <td>
                        <a href="{{ route('{$route}.show', \$item) }}">Show</a>
                        <a href="{{ route('{$route}.edit', \$item) }}">Edit</a>
                        <form action="{{ route('{$route}.destroy', \$item) }}" method="POST" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="99">No data found.</td></tr>
            @endforelse
        </tbody>
    </table>
</form>

{{ \$items->links() }}

<p>
    <a href="{{ route('{$route}.create') }}">Create New {$modelName}</a> |
    <a href="{{ route('{$route}.field-config') }}">Field Config</a>
</p>
BLADE;
    }

    private function buildCreateView(string $modelName, string $resourceView): string
    {
        $route = Str::snake(Str::pluralStudly($modelName));
        return <<<BLADE
<h1>Create {$modelName}</h1>

@if (\$errors->any())
    <ul>
        @foreach (\$errors->all() as \$error)
            <li>{{ \$error }}</li>
        @endforeach
    </ul>
@endif

<form method="POST" action="{{ route('{$route}.store') }}" enctype="multipart/form-data">
    @csrf
    @include('{$resourceView}._form')
    <button type="submit">Save</button>
</form>
BLADE;
    }

    private function buildEditView(string $modelName, string $resourceView): string
    {
        $route = Str::snake(Str::pluralStudly($modelName));
        return <<<BLADE
<h1>Edit {$modelName}</h1>

@if (\$errors->any())
    <ul>
        @foreach (\$errors->all() as \$error)
            <li>{{ \$error }}</li>
        @endforeach
    </ul>
@endif

<form method="POST" action="{{ route('{$route}.update', \$record) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('{$resourceView}._form')
    <button type="submit">Update</button>
</form>
BLADE;
    }

    private function buildShowView(string $modelName, string $resourceView): string
    {
        return <<<BLADE
<h1>{$modelName} Details</h1>

@foreach (\$fields as \$field)
    <p><strong>{{ \$field['label'] ?? \$field['name'] }}:</strong> {{ \$record->{\$field['name']} }}</p>
@endforeach

<a href="{{ url()->previous() }}">Back</a>
BLADE;
    }

    private function buildFormView(): string
    {
        return <<<'BLADE'
@foreach ($fields as $field)
    @php
        $name = $field['name'];
        $label = $field['label'] ?? $name;
        $input = $field['input'] ?? 'text';
        $required = (bool) ($field['required'] ?? false);
        $repeatable = (bool) ($field['repeatable'] ?? false);
        $condition = $field['conditional'] ?? null;
        $value = old($name, $record?->{$name} ?? null);
    @endphp

    <div style="margin-bottom: 12px;" data-condition='@json($condition)'>
        <label>{{ $label }}</label>

        @if ($input === 'textarea')
            <textarea name="{{ $name }}" rows="4">{{ $value }}</textarea>
        @elseif ($input === 'select' || $input === 'relation-select')
            <select name="{{ $name }}">
                <option value="">Select {{ $label }}</option>
                @foreach (($field['options'] ?? []) as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                @endforeach
            </select>
        @elseif ($input === 'checkbox' || $input === 'toggle')
            <input type="hidden" name="{{ $name }}" value="0">
            <input type="checkbox" name="{{ $name }}" value="1" @checked((bool) $value)>
        @elseif ($input === 'file')
            <input type="file" name="{{ $name }}">
            @if (!empty($value))
                <small>Current: {{ $value }}</small>
            @endif
        @else
            <input type="{{ $input }}" name="{{ $name }}" value="{{ $value }}">
        @endif

        @if ($repeatable)
            <small>Repeatable field enabled. Submit as array if needed.</small>
        @endif

        @if ($required)
            <small>Required</small>
        @endif
    </div>
@endforeach
BLADE;
    }

    private function buildFieldConfigView(string $modelName, string $resourceView): string
    {
        $route = Str::snake(Str::pluralStudly($modelName));
        return <<<BLADE
<h1>{$modelName} Field Configuration</h1>
<p>Update labels, input types, validation behavior, repeatable settings, and conditional field rules.</p>

@if (session('success'))
    <p>{{ session('success') }}</p>
@endif

<form method="POST" action="{{ route('{$route}.field-config.update') }}">
    @csrf
    @foreach (\$fields as \$index => \$field)
        <fieldset style="margin-bottom: 16px;">
            <legend>{{ \$field['name'] }}</legend>
            <input type="hidden" name="fields[{{ \$index }}][name]" value="{{ \$field['name'] }}">

            <label>Label</label>
            <input type="text" name="fields[{{ \$index }}][label]" value="{{ \$field['label'] ?? \$field['name'] }}">

            <label>Input Type</label>
            <input type="text" name="fields[{{ \$index }}][input]" value="{{ \$field['input'] ?? 'text' }}">

            <label>Validation Rules</label>
            <input type="text" name="fields[{{ \$index }}][rules]" value="{{ implode('|', \$field['rules'] ?? []) }}">

            <label>Required</label>
            <input type="hidden" name="fields[{{ \$index }}][required]" value="0">
            <input type="checkbox" name="fields[{{ \$index }}][required]" value="1" @checked(!empty(\$field['required']))>

            <label>Repeatable</label>
            <input type="hidden" name="fields[{{ \$index }}][repeatable]" value="0">
            <input type="checkbox" name="fields[{{ \$index }}][repeatable]" value="1" @checked(!empty(\$field['repeatable']))>

            <label>Conditional JSON</label>
            <textarea name="fields[{{ \$index }}][conditional]" rows="2">{{ json_encode(\$field['conditional'] ?? [], JSON_UNESCAPED_UNICODE) }}</textarea>
        </fieldset>
    @endforeach
    <button type="submit">Save Config</button>
</form>

<p><a href="{{ route('{$route}.index') }}">Back to list</a></p>
BLADE;
    }
}
