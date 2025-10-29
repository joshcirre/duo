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

        try {
            $data = $request->except(['_duo_pending_sync', '_duo_operation', '_duo_synced_at']);

            // Create new instance and fill with data
            $record = new $model;
            $record->fill($data);

            // Add user_id if the model has a user relationship
            // This bypasses $fillable for security-sensitive fields
            if ($request->user() && method_exists($model, 'user')) {
                $record->user_id = $request->user()->id;
            }

            $record->save();

            return response()->json($record, 201);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle database constraint errors
            \Log::error('[Duo] Database error creating record', [
                'table' => $table,
                'error' => $e->getMessage(),
                'data' => $data ?? null,
            ]);

            return response()->json([
                'error' => 'Database error',
                'message' => $this->getUserFriendlyErrorMessage($e),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('[Duo] Error creating record', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to create record',
                'message' => $e->getMessage(),
            ], 500);
        }
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

        try {
            $data = $request->except(['_duo_pending_sync', '_duo_operation', '_duo_synced_at']);
            $record->update($data);

            return response()->json($record);
        } catch (\Illuminate\Database\QueryException $e) {
            \Log::error('[Duo] Database error updating record', [
                'table' => $table,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Database error',
                'message' => $this->getUserFriendlyErrorMessage($e),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('[Duo] Error updating record', [
                'table' => $table,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to update record',
                'message' => $e->getMessage(),
            ], 500);
        }
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

    /**
     * Convert database exceptions to user-friendly error messages
     */
    protected function getUserFriendlyErrorMessage(\Illuminate\Database\QueryException $e): string
    {
        $message = $e->getMessage();

        // SQLite constraint errors
        if (str_contains($message, 'NOT NULL constraint failed')) {
            preg_match('/NOT NULL constraint failed: (\w+\.\w+)/', $message, $matches);
            $field = $matches[1] ?? 'a required field';

            return "Missing required field: {$field}";
        }

        if (str_contains($message, 'UNIQUE constraint failed')) {
            preg_match('/UNIQUE constraint failed: (\w+\.\w+)/', $message, $matches);
            $field = $matches[1] ?? 'field';

            return "Duplicate value for {$field}";
        }

        if (str_contains($message, 'FOREIGN KEY constraint failed')) {
            return 'Invalid reference to related record';
        }

        // MySQL constraint errors
        if (str_contains($message, "doesn't have a default value")) {
            return 'Missing required field';
        }

        if (str_contains($message, 'Duplicate entry')) {
            return 'This record already exists';
        }

        // Generic fallback
        return 'Database constraint violation';
    }
}
