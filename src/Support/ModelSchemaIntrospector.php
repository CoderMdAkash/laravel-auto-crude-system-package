<?php

namespace Akash\LaravelAutoCrude\Support;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ModelSchemaIntrospector
{
    public function __construct(private Command $command)
    {
    }

    public function inspect(string $modelClass, string $table): array
    {
        /** @var Model $model */
        $model = new $modelClass();
        $tableName = $table ?: $model->getTable();

        try {
            $columns = Schema::getColumnListing($tableName);
        } catch (Throwable $throwable) {
            $this->command->warn('Table "' . $tableName . '" not found. Falling back to model fillable fields.');
            return $this->fallbackFromModel($model);
        }
        $driver = DB::connection()->getDriverName();
        $databaseName = DB::connection()->getDatabaseName();
        $foreignMap = $this->foreignMap($driver, $databaseName, $tableName);
        $enumMap = $this->enumMap($driver, $databaseName, $tableName);

        $formFields = [];
        $validationRules = [];
        $searchable = [];
        $sortable = [];
        $enumFilters = [];
        $booleanFilters = [];
        $dateFilters = [];
        $relationFilters = [];

        foreach ($columns as $column) {
            if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $type = Schema::getColumnType($tableName, $column);
            $isNullable = $this->isNullable($driver, $databaseName, $tableName, $column);
            $isForeign = isset($foreignMap[$column]);
            $enumValues = $enumMap[$column] ?? [];

            $input = $this->resolveInputType($type, $column, $isForeign, ! empty($enumValues));
            $rules = $this->resolveValidationRules(
                $column,
                $type,
                $isNullable,
                $isForeign,
                $foreignMap[$column] ?? null,
                $enumValues
            );

            $field = [
                'name' => $column,
                'label' => Str::headline($column),
                'input' => $input,
                'rules' => $rules,
                'required' => ! $isNullable,
                'repeatable' => in_array($type, ['json', 'array'], true),
                'conditional' => null,
                'options' => ! empty($enumValues) ? array_combine($enumValues, $enumValues) : [],
            ];

            if ($isForeign) {
                $field['relation'] = [
                    'table' => $foreignMap[$column]['table'],
                    'column' => $foreignMap[$column]['column'],
                ];
                $field['input'] = 'relation-select';
            }

            $formFields[] = $field;
            $validationRules[$column] = implode('|', $rules);
            $sortable[] = $column;

            if (in_array($type, ['string', 'text'], true)) {
                $searchable[] = $column;
            }

            if (! empty($enumValues)) {
                $enumFilters[] = $column;
            }

            if (in_array($type, ['boolean', 'tinyint'], true)) {
                $booleanFilters[] = $column;
            }

            if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
                $dateFilters[] = $column;
            }

            if ($isForeign) {
                $relationFilters[] = $column;
            }
        }

        return [
            'form_fields' => $formFields,
            'validation_rules' => $validationRules,
            'searchable' => array_values(array_unique($searchable)),
            'sortable' => array_values(array_unique($sortable)),
            'default_sort' => '-id',
            'enum_filters' => array_values(array_unique($enumFilters)),
            'boolean_filters' => array_values(array_unique($booleanFilters)),
            'date_filters' => array_values(array_unique($dateFilters)),
            'relation_filters' => array_values(array_unique($relationFilters)),
            'soft_deletes' => in_array('deleted_at', Schema::getColumnListing($tableName), true),
        ];
    }

    private function resolveInputType(string $type, string $column, bool $isForeign, bool $isEnum): string
    {
        if ($isEnum) {
            return 'select';
        }

        if ($isForeign) {
            return 'relation-select';
        }

        return match ($type) {
            'text', 'mediumtext', 'longtext', 'json' => 'textarea',
            'boolean', 'tinyint' => 'toggle',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime-local',
            'integer', 'bigint', 'smallint', 'decimal', 'double', 'float' => 'number',
            default => Str::contains($column, 'file') ? 'file' : 'text',
        };
    }

    private function resolveValidationRules(
        string $column,
        string $type,
        bool $nullable,
        bool $isForeign,
        ?array $foreign,
        array $enumValues
    ): array {
        $rules = [$nullable ? 'nullable' : 'required'];

        if (! empty($enumValues)) {
            $rules[] = 'in:' . implode(',', $enumValues);
            return $rules;
        }

        if ($isForeign && $foreign !== null) {
            $rules[] = 'exists:' . $foreign['table'] . ',' . $foreign['column'];
            return $rules;
        }

        $rules[] = match ($type) {
            'text', 'mediumtext', 'longtext' => 'string',
            'json' => 'array',
            'integer', 'bigint', 'smallint' => 'integer',
            'decimal', 'double', 'float' => 'numeric',
            'boolean', 'tinyint' => 'boolean',
            'date', 'datetime', 'timestamp' => 'date',
            default => 'string',
        };

        if (Str::contains($column, ['email'])) {
            $rules[] = 'email';
        }

        if (Str::contains($column, ['name', 'title'])) {
            $rules[] = 'min:2';
            $rules[] = 'max:255';
        }

        return $rules;
    }

    private function foreignMap(string $driver, ?string $databaseName, string $tableName): array
    {
        if ($driver !== 'mysql' || $databaseName === null) {
            return [];
        }

        $rows = DB::select(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$databaseName, $tableName]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row->COLUMN_NAME] = [
                'table' => $row->REFERENCED_TABLE_NAME,
                'column' => $row->REFERENCED_COLUMN_NAME,
            ];
        }

        return $map;
    }

    private function enumMap(string $driver, ?string $databaseName, string $tableName): array
    {
        if ($driver !== 'mysql' || $databaseName === null) {
            return [];
        }

        $rows = DB::select(
            "SELECT COLUMN_NAME, COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND DATA_TYPE = 'enum'",
            [$databaseName, $tableName]
        );

        $map = [];
        foreach ($rows as $row) {
            preg_match_all("/'([^']+)'/", $row->COLUMN_TYPE, $matches);
            $map[$row->COLUMN_NAME] = $matches[1] ?? [];
        }

        return $map;
    }

    private function isNullable(string $driver, ?string $databaseName, string $tableName, string $column): bool
    {
        if ($driver !== 'mysql' || $databaseName === null) {
            return true;
        }

        $row = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1',
            [$databaseName, $tableName, $column]
        );

        return ($row->IS_NULLABLE ?? 'YES') === 'YES';
    }

    private function fallbackFromModel(Model $model): array
    {
        $fillable = $model->getFillable();
        $fields = [];
        $validationRules = [];

        foreach ($fillable as $column) {
            $fields[] = [
                'name' => $column,
                'label' => Str::headline($column),
                'input' => 'text',
                'rules' => ['nullable', 'string'],
                'required' => false,
                'repeatable' => false,
                'conditional' => null,
                'options' => [],
            ];

            $validationRules[$column] = 'nullable|string';
        }

        return [
            'form_fields' => $fields,
            'validation_rules' => $validationRules,
            'searchable' => $fillable,
            'sortable' => empty($fillable) ? ['id'] : $fillable,
            'default_sort' => '-id',
            'enum_filters' => [],
            'boolean_filters' => [],
            'date_filters' => [],
            'relation_filters' => [],
            'soft_deletes' => false,
        ];
    }
}
