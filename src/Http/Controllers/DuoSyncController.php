<?php

declare(strict_types=1);

namespace JoshCirre\Duo\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JoshCirre\Duo\ModelRegistry;

class DuoSyncController extends Controller
{
    public function __construct(
        protected ModelRegistry $registry
    ) {}

    /**
     * List all records for a model
     */
    public function index(string $table): JsonResponse
    {
        $model = $this->getModelForTable($table);

        if (! $model) {
            return response()->json(['error' => 'Model not found'], 404);
        }

        $records = $model::all();

        return response()->json($records);
    }

    /**
     * Get a single record
     */
    public function show(string $table, int|string $id): JsonResponse
    {
        $model = $this->getModelForTable($table);

        if (! $model) {
            return response()->json(['error' => 'Model not found'], 404);
        }

        $record = $model::find($id);

        if (! $record) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json($record);
    }

    /**
     * Create a new record
     */
    public function store(Request $request, string $table): JsonResponse
    {
        $model = $this->getModelForTable($table);

        if (! $model) {
            return response()->json(['error' => 'Model not found'], 404);
        }

        $data = $request->except(['_duo_pending_sync', '_duo_operation', '_duo_synced_at']);
        $record = $model::create($data);

        return response()->json($record, 201);
    }

    /**
     * Update an existing record
     */
    public function update(Request $request, string $table, int|string $id): JsonResponse
    {
        $model = $this->getModelForTable($table);

        if (! $model) {
            return response()->json(['error' => 'Model not found'], 404);
        }

        $record = $model::find($id);

        if (! $record) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->except(['_duo_pending_sync', '_duo_operation', '_duo_synced_at']);
        $record->update($data);

        return response()->json($record);
    }

    /**
     * Delete a record
     */
    public function destroy(string $table, int|string $id): JsonResponse
    {
        $model = $this->getModelForTable($table);

        if (! $model) {
            return response()->json(['error' => 'Model not found'], 404);
        }

        $record = $model::find($id);

        if (! $record) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $record->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    /**
     * Get the model class for a table name
     */
    protected function getModelForTable(string $table): ?string
    {
        foreach ($this->registry->all() as $modelClass => $metadata) {
            if ($metadata['table'] === $table) {
                return $modelClass;
            }
        }

        return null;
    }
}
