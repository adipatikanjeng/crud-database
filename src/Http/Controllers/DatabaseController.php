<?php

namespace LaravelAdminPanel\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelAdminPanel\Database\DatabaseUpdater;
use LaravelAdminPanel\Database\Schema\Column;
use LaravelAdminPanel\Database\Schema\Identifier;
use LaravelAdminPanel\Database\Schema\SchemaManager;
use LaravelAdminPanel\Database\Schema\Table;
use LaravelAdminPanel\Database\Types\Type;
use LaravelAdminPanel\Events\CrudAdded;
use LaravelAdminPanel\Events\CrudDeleted;
use LaravelAdminPanel\Events\CrudUpdated;
use LaravelAdminPanel\Events\TableAdded;
use LaravelAdminPanel\Events\TableDeleted;
use LaravelAdminPanel\Events\TableUpdated;
use LaravelAdminPanel\Facades\Admin;
use LaravelAdminPanel\Models\DataRow;
use LaravelAdminPanel\Models\DataType;
use LaravelAdminPanel\Models\Permission;

class DatabaseController extends BaseController
{
    public function index()
    {
        Admin::canOrFail('browse_database');

        $dataTypes = Admin::model('DataType')->select('id', 'name', 'slug')->get()->keyBy('name')->toArray();

        $tables = array_map(function ($table) use ($dataTypes) {
            $table = [
                'name'          => $table,
                'slug'          => isset($dataTypes[$table]['slug']) ? $dataTypes[$table]['slug'] : null,
                'dataTypeId'    => isset($dataTypes[$table]['id']) ? $dataTypes[$table]['id'] : null,
            ];

            return (object) $table;
        }, SchemaManager::listTableNames());

        return Admin::view('admin::tools.database.index')->with(compact('dataTypes', 'tables'));
    }

    /**
     * Create database table.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function create()
    {
        Admin::canOrFail('browse_database');

        $db = $this->prepareDbManager('create');

        return Admin::view('admin::tools.database.edit-add', compact('db'));
    }

    /**
     * Store new database table.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        Admin::canOrFail('browse_database');

        try {
            Type::registerCustomPlatformTypes();

            $table = Table::make($request->table);
            SchemaManager::createTable($table);

            if (isset($request->create_model) && $request->create_model == 'on') {
                $modelNamespace = config('admin.models.namespace', app()->getNamespace());
                $params = [
                    'name' => $modelNamespace.Str::studly(Str::singular($table->name)),
                ];

                // if (in_array('deleted_at', $request->input('field.*'))) {
                //     $params['--softdelete'] = true;
                // }

                if (isset($request->create_migration) && $request->create_migration == 'on') {
                    $params['--migration'] = true;
                }

                Artisan::call('admin:make:model', $params);

                event(new TableAdded($table));
            } elseif (isset($request->create_migration) && $request->create_migration == 'on') {
                Artisan::call('make:migration', [
                    'name'    => 'create_'.$table->name.'_table',
                    '--table' => $table->name,
                ]);
            }

            return redirect()
               ->route('admin.database.index')
               ->with($this->alertSuccess(__('admin.database.success_create_table', ['table' => $table->name])));
        } catch (Exception $e) {
            return back()->with($this->alertException($e))->withInput();
        }
    }

    /**
     * Edit database table.
     *
     * @param string $table
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function edit($table)
    {
        Admin::canOrFail('browse_database');

        if (!SchemaManager::tableExists($table)) {
            return redirect()
                ->route('admin.database.index')
                ->with($this->alertError(__('admin.database.edit_table_not_exist')));
        }

        $db = $this->prepareDbManager('update', $table);

        return Admin::view('admin::tools.database.edit-add', compact('db'));
    }

    /**
     * Update database table.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        Admin::canOrFail('browse_database');

        $table = json_decode($request->table, true);

        try {
            DatabaseUpdater::update($table);
            // TODO: synch BREAD with Table
            // $this->cleanOldAndCreateNew($request->original_name, $request->name);
            event(new TableUpdated($table));
        } catch (Exception $e) {
            return back()->with($this->alertException($e))->withInput();
        }

        return redirect()
               ->route('admin.database.index')
               ->with($this->alertSuccess(__('admin.database.success_create_table', ['table' => $table['name']])));
    }

    protected function prepareDbManager($action, $table = '')
    {
        $db = new \stdClass();

        // Need to get the types first to register custom types
        $db->types = Type::getPlatformTypes();

        if ($action == 'update') {
            $db->table = SchemaManager::listTableDetails($table);
            $db->formAction = route('admin.database.update', $table);
        } else {
            $db->table = new Table('New Table');

            // Add prefilled columns
            $db->table->addColumn('id', 'integer', [
                'unsigned'      => true,
                'notnull'       => true,
                'autoincrement' => true,
            ]);

            $db->table->setPrimaryKey(['id'], 'primary');

            $db->formAction = route('admin.database.store');
        }

        $oldTable = old('table');
        $db->oldTable = $oldTable ? $oldTable : json_encode(null);
        $db->action = $action;
        $db->identifierRegex = Identifier::REGEX;
        $db->platform = SchemaManager::getDatabasePlatform()->getName();

        return $db;
    }

    public function cleanOldAndCreateNew($originalName, $tableName)
    {
        if (!empty($originalName) && $originalName != $tableName) {
            $dt = DB::table('data_types')->where('name', $originalName);
            if ($dt->get()) {
                $dt->delete();
            }

            $perm = DB::table('permissions')->where('table_name', $originalName);
            if ($perm->get()) {
                $perm->delete();
            }

            $params = ['name' => Str::studly(Str::singular($tableName))];
            Artisan::call('admin:make:model', $params);
        }
    }

    public function reorder_column(Request $request)
    {
        Admin::canOrFail('browse_database');

        if ($request->ajax()) {
            $table = $request->table;
            $column = $request->column;
            $after = $request->after;
            if ($after == null) {
                // SET COLUMN TO THE TOP
                DB::query("ALTER $table MyTable CHANGE COLUMN $column FIRST");
            }

            return 1;
        }

        return 0;
    }

    public function show($table)
    {
        Admin::canOrFail('browse_database');

        return response()->json(SchemaManager::describeTable($table));
    }

    public function destroy($table)
    {
        Admin::canOrFail('browse_database');

        try {
            SchemaManager::dropTable($table);
            event(new TableDeleted($table));

            return redirect()
                ->route('admin.database.index')
                ->with($this->alertSuccess(__('admin.database.success_delete_table', ['table' => $table])));
        } catch (Exception $e) {
            return back()->with($this->alertException($e));
        }
    }

    /********** BREAD METHODS **********/

    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function addCrud(Request $request, $table)
    {
        Admin::canOrFail('browse_database');

        $data = $this->prepopulateCrudInfo($table);
        $data['fieldOptions'] = SchemaManager::describeTable($table);

        return Admin::view('admin::tools.database.edit-add-crud', $data);
    }

    private function prepopulateCrudInfo($table)
    {
        $displayName = Str::singular(implode(' ', explode('_', Str::title($table))));
        $modelNamespace = config('admin.models.namespace', app()->getNamespace());
        if (empty($modelNamespace)) {
            $modelNamespace = app()->getNamespace();
        }

        return [
            'isModelTranslatable'  => true,
            'table'                => $table,
            'slug'                 => Str::slug($table),
            'display_name'         => $displayName,
            'display_name_plural'  => Str::plural($displayName),
            'model_name'           => $modelNamespace.Str::studly(Str::singular($table)),
            'generate_permissions' => true,
            'server_side'          => false,
        ];
    }

    public function storeCrud(Request $request)
    {
        Admin::canOrFail('browse_database');

        try {
            $dataType = Admin::model('DataType');
            $res = $dataType->updateDataType($request->all(), true);
            $data = $res
                ? $this->alertSuccess(__('admin.database.success_created_crud'))
                : $this->alertError(__('admin.database.error_creating_crud'));
            if ($res) {
                event(new CrudAdded($dataType, $data));
            }

            return redirect()->route('admin.database.index')->with($data);
        } catch (Exception $e) {
            return redirect()->route('admin.database.index')->with($this->alertException($e, 'Saving Failed'));
        }
    }

    public function addEditCrud($table)
    {
        Admin::canOrFail('browse_database');

        $dataType = Admin::model('DataType')->whereName($table)->first();

        $fieldOptions = SchemaManager::describeTable($dataType->name);

        $isModelTranslatable = is_crud_translatable($dataType);
        $tables = SchemaManager::listTableNames();
        $dataTypeRelationships = Admin::model('DataRow')->where('data_type_id', '=', $dataType->id)->where('type', '=', 'relationship')->get();

        return Admin::view('admin::tools.database.edit-add-crud', compact('dataType', 'fieldOptions', 'isModelTranslatable', 'tables', 'dataTypeRelationships'));
    }

    public function updateCrud(Request $request, $id)
    {
        Admin::canOrFail('browse_database');

        /* @var \LaravelAdminPanel\Models\DataType $dataType */
        try {
            $dataType = Admin::model('DataType')->find($id);

            // Prepare Translations and Transform data
            $translations = is_crud_translatable($dataType)
                ? $dataType->prepareTranslations($request)
                : [];

            $res = $dataType->updateDataType($request->all(), true);
            $data = $res
                ? $this->alertSuccess(__('admin.database.success_update_crud', ['datatype' => $dataType->name]))
                : $this->alertError(__('admin.database.error_updating_crud'));
            if ($res) {
                event(new CrudUpdated($dataType, $data));
            }

            // Save translations if applied
            $dataType->saveTranslations($translations);

            return redirect()->route('admin.database.index')->with($data);
        } catch (Exception $e) {
            return back()->with($this->alertException($e, __('admin.generic.update_failed')));
        }
    }

    public function deleteCrud($id)
    {
        Admin::canOrFail('browse_database');

        /* @var \LaravelAdminPanel\Models\DataType $dataType */
        $dataType = Admin::model('DataType')->find($id);

        // Delete Translations, if present
        if (is_crud_translatable($dataType)) {
            $dataType->deleteAttributeTranslations($dataType->getTranslatableAttributes());
        }

        $res = Admin::model('DataType')->destroy($id);
        $data = $res
            ? $this->alertSuccess(__('admin.database.success_remove_crud', ['datatype' => $dataType->name]))
            : $this->alertError(__('admin.database.error_updating_crud'));
        if ($res) {
            event(new CrudDeleted($dataType, $data));
        }

        if (!is_null($dataType)) {
            Admin::model('Permission')->removeFrom($dataType->name);
        }

        return redirect()->route('admin.database.index')->with($data);
    }

    public function addRelationship(Request $request)
    {
        $relationshipField = $this->getRelationshipField($request);

        if (!class_exists($request->relationship_model)) {
            return back()->with([
                    'message'    => 'Model Class '.$request->relationship_model.' does not exist. Please create Model before creating relationship.',
                    'alert-type' => 'error',
                ]);
        }

        try {
            DB::beginTransaction();

            $relationship_column = $request->relationship_column_belongs_to;
            if ($request->relationship_type == 'hasOne' || $request->relationship_type == 'hasMany') {
                $relationship_column = $request->relationship_column;
            }

            // Build the relationship details
            $relationshipDetails = json_encode([
                'model'       => $request->relationship_model,
                'table'       => $request->relationship_table,
                'type'        => $request->relationship_type,
                'column'      => $relationship_column,
                'key'         => $request->relationship_key,
                'label'       => $request->relationship_label,
                'pivot_table' => $request->relationship_pivot,
                'pivot'       => ($request->relationship_type == 'belongsToMany') ? '1' : '0',
            ]);

            $newRow = new DataRow();

            $newRow->data_type_id = $request->data_type_id;
            $newRow->field = $relationshipField;
            $newRow->type = 'relationship';
            $newRow->display_name = $request->relationship_table;
            $newRow->required = 0;

            foreach (['browse', 'read', 'edit', 'add', 'delete'] as $check) {
                $newRow->{$check} = 1;
            }

            $newRow->details = $relationshipDetails;
            $newRow->order = intval(Admin::model('DataType')->find($request->data_type_id)->lastRow()->order) + 1;

            if (!$newRow->save()) {
                return back()->with([
                    'message'    => 'Error saving new relationship row for '.$request->relationship_table,
                    'alert-type' => 'error',
                ]);
            }

            DB::commit();

            return back()->with([
                'message'    => 'Successfully created new relationship for '.$request->relationship_table,
                'alert-type' => 'success',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with([
                'message'    => 'Error creating new relationship: '.$e->getMessage(),
                'alert-type' => 'error',
            ]);
        }
    }

    private function getRelationshipField($request)
    {
        // We need to make sure that we aren't creating an already existing field

        $dataType = Admin::model('DataType')->find($request->data_type_id);

        $field = str_singular($dataType->name).'_'.$request->relationship_type.'_'.str_singular($request->relationship_table).'_relationship';

        $relationshipFieldOriginal = $relationshipField = strtolower($field);

        $existingRow = Admin::model('DataRow')->where('field', '=', $relationshipField)->first();
        $index = 1;

        while (isset($existingRow->id)) {
            $relationshipField = $relationshipFieldOriginal.'_'.$index;
            $existingRow = Admin::model('DataRow')->where('field', '=', $relationshipField)->first();
            $index += 1;
        }

        return $relationshipField;
    }

    public function deleteRelationship($id)
    {
        Admin::model('DataRow')->destroy($id);

        return back()->with([
                'message'    => 'Successfully deleted relationship.',
                'alert-type' => 'success',
            ]);
    }
}
