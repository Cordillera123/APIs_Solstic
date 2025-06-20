<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TipoOficina;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;


class TipoOficinaController extends Controller
{
    /**
     * Listar todos los tipos de oficina
     * GET /api/tipos-oficina
     */
    public function index(): JsonResponse
    {
        try {
            $tiposOficina = TipoOficina::all();
            
            return response()->json([
                'success' => true,
                'message' => 'Tipos de oficina obtenidos correctamente',
                'data' => $tiposOficina
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los tipos de oficina',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo tipo de oficina
     * POST /api/tipos-oficina
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validaciones
            $validator = Validator::make($request->all(), [
                'tofici_descripcion' => 'required|string|max:40|unique:gaf_tofici,tofici_descripcion',
                'tofici_abreviatura' => 'required|string|max:10|unique:gaf_tofici,tofici_abreviatura'
            ], [
                'tofici_descripcion.required' => 'La descripción es obligatoria',
                'tofici_descripcion.unique' => 'Ya existe un tipo de oficina con esta descripción',
                'tofici_abreviatura.required' => 'La abreviatura es obligatoria',
                'tofici_abreviatura.unique' => 'Ya existe un tipo de oficina con esta abreviatura'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Obtener el siguiente ID manualmente (TEMPORAL)
            $nextId = DB::table('gaf_tofici')->max('tofici_codigo') + 1;

            // Crear tipo de oficina
            $tipoOficina = TipoOficina::create([
                'tofici_codigo' => $nextId,
                'tofici_descripcion' => trim($request->tofici_descripcion),
                'tofici_abreviatura' => strtoupper(trim($request->tofici_abreviatura))
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tipo de oficina creado correctamente',
                'data' => $tipoOficina
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el tipo de oficina',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un tipo de oficina específico
     * GET /api/tipos-oficina/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $tipoOficina = TipoOficina::find($id);

            if (!$tipoOficina) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de oficina no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Tipo de oficina obtenido correctamente',
                'data' => $tipoOficina
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el tipo de oficina',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un tipo de oficina
     * PUT /api/tipos-oficina/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $tipoOficina = TipoOficina::find($id);

            if (!$tipoOficina) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de oficina no encontrado'
                ], 404);
            }

            // Validaciones (excluyendo el registro actual)
            $validator = Validator::make($request->all(), [
                'tofici_descripcion' => 'required|string|max:40|unique:gaf_tofici,tofici_descripcion,' . $id . ',tofici_codigo',
                'tofici_abreviatura' => 'required|string|max:10|unique:gaf_tofici,tofici_abreviatura,' . $id . ',tofici_codigo'
            ], [
                'tofici_descripcion.required' => 'La descripción es obligatoria',
                'tofici_descripcion.unique' => 'Ya existe un tipo de oficina con esta descripción',
                'tofici_abreviatura.required' => 'La abreviatura es obligatoria',
                'tofici_abreviatura.unique' => 'Ya existe un tipo de oficina con esta abreviatura'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Actualizar
            $tipoOficina->update([
                'tofici_descripcion' => trim($request->tofici_descripcion),
                'tofici_abreviatura' => strtoupper(trim($request->tofici_abreviatura))
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tipo de oficina actualizado correctamente',
                'data' => $tipoOficina
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el tipo de oficina',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un tipo de oficina
     * DELETE /api/tipos-oficina/{id}
     */
    public function destroy($id): JsonResponse
    {
        try {
            $tipoOficina = TipoOficina::find($id);

            if (!$tipoOficina) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de oficina no encontrado'
                ], 404);
            }

            // Verificar si tiene oficinas asociadas
            $oficinasAsociadas = $tipoOficina->oficinas()->count();
            
            if ($oficinasAsociadas > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el tipo de oficina porque tiene ' . $oficinasAsociadas . ' oficina(s) asociada(s)'
                ], 400);
            }

            $tipoOficina->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tipo de oficina eliminado correctamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el tipo de oficina',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener tipos de oficina activos para select
     * GET /api/tipos-oficina/activos
     */
    public function activos(): JsonResponse
    {
        try {
            $tiposOficina = TipoOficina::select('tofici_codigo', 'tofici_descripcion', 'tofici_abreviatura')
                ->get()
                ->map(function ($tipo) {
                    return [
                        'value' => $tipo->tofici_codigo,
                        'label' => $tipo->tofici_descripcion,
                        'abreviatura' => $tipo->tofici_abreviatura
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Tipos de oficina activos obtenidos correctamente',
                'data' => $tiposOficina
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los tipos de oficina activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}