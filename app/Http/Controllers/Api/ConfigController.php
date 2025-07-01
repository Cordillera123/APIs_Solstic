<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ConfigController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $search = $request->get('search', '');
            $perPage = $request->get('per_page', 15);

            $query = Config::select('conf_id', 'conf_nom', 'conf_detalle');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('conf_nom', 'ILIKE', "%{$search}%")
                        ->orWhere('conf_detalle', 'ILIKE', "%{$search}%");
                });
            }

            $configs = $query->orderBy('conf_id')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $configs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener configuraciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * tiempo de espera para el cierre de sesión
     */
    public function getTiempoEspera(Request $request)
    {
        try {
            $config = Config::select('conf_id', 'conf_nom', 'conf_detalle')
                ->whereRaw('LOWER(conf_nom) = LOWER(?)', ['tiempo de espera'])
                ->first();

            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Configuración "tiempo de espera" no encontrada'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $config
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'conf_nom' => 'required|string|max:100|unique:tbl_config,conf_nom',
            'conf_detalle' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $config = Config::create([
                'conf_nom' => $request->conf_nom,
                'conf_detalle' => $request->conf_detalle,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Configuración creada exitosamente',
                'data' => $config->getInfoBasica()
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $config = Config::find($id);

            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Configuración no encontrada'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $config
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            Log::info("🔧 [ConfigController@update] Iniciando actualización", [
                'id' => $id,
                'datos_recibidos' => $request->all(),
            ]);

            $config = Config::find($id);
            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Configuración no encontrada'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'conf_nom' => "sometimes|required|string|max:100|unique:tbl_config,conf_nom,{$id},conf_id",
                'conf_detalle' => 'sometimes|required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $dataToUpdate = [];
            if ($request->has('conf_nom')) $dataToUpdate['conf_nom'] = $request->conf_nom;
            if ($request->has('conf_detalle')) $dataToUpdate['conf_detalle'] = $request->conf_detalle;

            $config->update($dataToUpdate);

            DB::commit();

            // Log de la respuesta final
            Log::info("✅ [ConfigController@update] Configuración actualizada", [
                'id' => $id,
                'nueva_data' => [
                    'conf_id' => $config->conf_id,
                    'conf_nom' => $config->conf_nom,
                    'conf_detalle' => $config->conf_detalle,
                ]
            ]);

            // ✅ Usamos fresh con campos específicos o evitamos usarlo
            return response()->json([
                'status' => 'success',
                'message' => 'Configuración actualizada exitosamente',
                'data' => [
                    'conf_id' => $config->conf_id,
                    'conf_nom' => $config->conf_nom,
                    'conf_detalle' => $config->conf_detalle,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ [ConfigController@update] Error interno", [
                'id' => $id,
                'mensaje' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $config = Config::find($id);
            if (!$config) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Configuración no encontrada'
                ], 404);
            }

            DB::beginTransaction();
            $config->delete();
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Configuración eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar configuración: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
 * ✅ NUEVO: Actualizar configuración por nombre
 */
public function updateByName(Request $request)
{
    try {
        $request->validate([
            'conf_nom' => 'required|string|max:100',
            'conf_detalle' => 'required|string'
        ]);

        $config = Config::where('conf_nom', $request->conf_nom)->first();

        if ($config) {
            $config->update(['conf_detalle' => $request->conf_detalle]);
        } else {
            $config = Config::create([
                'conf_nom' => $request->conf_nom,
                'conf_detalle' => $request->conf_detalle
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Configuración actualizada correctamente',
            'data' => $config->getInfoBasica()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al actualizar configuración: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * ✅ NUEVO: Obtener por nombre con filtro
 */
public function getByNameFilter(Request $request)
{
    try {
        $configName = $request->get('conf_nom');
        
        if (!$configName) {
            return $this->index($request);
        }

        $config = Config::where('conf_nom', $configName)->get();

        return response()->json([
            'status' => 'success',
            'data' => $config
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al buscar configuración: ' . $e->getMessage()
        ], 500);
    }
}
}
