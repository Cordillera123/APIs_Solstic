<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Estado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class EstadoController extends Controller
{
    /**
     * Obtener todos los estados con información adicional
     */
    public function index(Request $request)
    {
        try {
            $search = $request->get('search', '');
            $perPage = $request->get('per_page', 15);

            $query = DB::table('tbl_est')
                ->select(
                    'tbl_est.est_id',
                    'tbl_est.est_nom',
                    DB::raw('COUNT(DISTINCT tbl_usu.usu_id) as usuarios_count')
                )
                ->leftJoin('tbl_usu', 'tbl_est.est_id', '=', 'tbl_usu.est_id');

            if (!empty($search)) {
                $query->where('tbl_est.est_nom', 'ILIKE', "%{$search}%");
            }

            $estados = $query->groupBy('tbl_est.est_id', 'tbl_est.est_nom')
                           ->orderBy('tbl_est.est_id')
                           ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $estados
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estados: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear nuevo estado
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'est_nom' => 'required|string|max:100|unique:tbl_est,est_nom'
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

            $estado = Estado::create([
                'est_nom' => $request->est_nom
            ]);

            // Obtener el estado creado con contador
            $estadoCompleto = DB::table('tbl_est')
                ->select(
                    'tbl_est.est_id',
                    'tbl_est.est_nom',
                    DB::raw('0 as usuarios_count')
                )
                ->where('tbl_est.est_id', $estado->est_id)
                ->first();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Estado creado exitosamente',
                'data' => $estadoCompleto
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estado específico
     */
    public function show($id)
    {
        try {
            $estado = DB::table('tbl_est')
                ->select(
                    'tbl_est.est_id',
                    'tbl_est.est_nom',
                    DB::raw('COUNT(DISTINCT tbl_usu.usu_id) as usuarios_count')
                )
                ->leftJoin('tbl_usu', 'tbl_est.est_id', '=', 'tbl_usu.est_id')
                ->where('tbl_est.est_id', $id)
                ->groupBy('tbl_est.est_id', 'tbl_est.est_nom')
                ->first();

            if (!$estado) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Estado no encontrado'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $estado
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar estado
     */
    public function update(Request $request, $id)
    {
        $estado = Estado::find($id);
        
        if (!$estado) {
            return response()->json([
                'status' => 'error',
                'message' => 'Estado no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'est_nom' => 'required|string|max:100|unique:tbl_est,est_nom,' . $id . ',est_id'
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

            $estado->update([
                'est_nom' => $request->est_nom
            ]);

            // Obtener el estado actualizado con contador
            $estadoCompleto = DB::table('tbl_est')
                ->select(
                    'tbl_est.est_id',
                    'tbl_est.est_nom',
                    DB::raw('COUNT(DISTINCT tbl_usu.usu_id) as usuarios_count')
                )
                ->leftJoin('tbl_usu', 'tbl_est.est_id', '=', 'tbl_usu.est_id')
                ->where('tbl_est.est_id', $id)
                ->groupBy('tbl_est.est_id', 'tbl_est.est_nom')
                ->first();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Estado actualizado exitosamente',
                'data' => $estadoCompleto
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar estado
     */
    public function destroy($id)
    {
        try {
            $estado = Estado::find($id);
            
            if (!$estado) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Estado no encontrado'
                ], 404);
            }

            // Verificar si hay usuarios con este estado
            $usuariosCount = DB::table('tbl_usu')
                ->where('est_id', $id)
                ->count();

            if ($usuariosCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No se puede eliminar el estado porque tiene {$usuariosCount} usuario(s) asignado(s)"
                ], 400);
            }

            // Verificar que no sea un estado crítico (Activo o Inactivo)
            if (in_array($id, [1, 2])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se pueden eliminar los estados básicos del sistema (Activo/Inactivo)'
                ], 400);
            }

            DB::beginTransaction();
            
            $estado->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Estado eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener usuarios de un estado específico
     */
    public function getUsuarios($id)
    {
        try {
            $estado = Estado::find($id);
            
            if (!$estado) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Estado no encontrado'
                ], 404);
            }

            $usuarios = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->select(
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_nom2',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_ape2',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'tbl_per.per_nom as perfil',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
                )
                ->where('tbl_usu.est_id', $id)
                ->orderBy('tbl_usu.usu_nom')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'estado' => $estado,
                    'usuarios' => $usuarios,
                    'total_usuarios' => $usuarios->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuarios del estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar usuarios masivamente de un estado a otro
     */
    public function cambiarUsuariosMasivo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'estado_origen' => 'required|integer|exists:tbl_est,est_id',
            'estado_destino' => 'required|integer|exists:tbl_est,est_id|different:estado_origen',
            'usuario_ids' => 'nullable|array',
            'usuario_ids.*' => 'integer|exists:tbl_usu,usu_id'
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

            $estadoOrigen = $request->estado_origen;
            $estadoDestino = $request->estado_destino;
            $usuarioIds = $request->usuario_ids;

            // Si se proporcionan IDs específicos, cambiar solo esos usuarios
            if (!empty($usuarioIds)) {
                $usuariosAfectados = DB::table('tbl_usu')
                    ->whereIn('usu_id', $usuarioIds)
                    ->where('est_id', $estadoOrigen)
                    ->update(['est_id' => $estadoDestino]);
            } else {
                // Si no se proporcionan IDs, cambiar todos los usuarios del estado origen
                $usuariosAfectados = DB::table('tbl_usu')
                    ->where('est_id', $estadoOrigen)
                    ->update(['est_id' => $estadoDestino]);
            }

            // Si el estado destino es "Inactivo", revocar tokens de los usuarios afectados
            if ($estadoDestino == 2) { // Asumiendo que 2 es "Inactivo"
                if (!empty($usuarioIds)) {
                    DB::table('personal_access_tokens')
                        ->whereIn('tokenable_id', $usuarioIds)
                        ->where('tokenable_type', 'App\\Models\\Usuario')
                        ->delete();
                } else {
                    // Obtener IDs de usuarios que estaban en el estado origen
                    $usuarioIdsAfectados = DB::table('tbl_usu')
                        ->where('est_id', $estadoDestino)
                        ->pluck('usu_id');
                    
                    DB::table('personal_access_tokens')
                        ->whereIn('tokenable_id', $usuarioIdsAfectados)
                        ->where('tokenable_type', 'App\\Models\\Usuario')
                        ->delete();
                }
            }

            DB::commit();

            // Obtener nombres de los estados
            $nombreOrigen = DB::table('tbl_est')->where('est_id', $estadoOrigen)->value('est_nom');
            $nombreDestino = DB::table('tbl_est')->where('est_id', $estadoDestino)->value('est_nom');

            return response()->json([
                'status' => 'success',
                'message' => "Se cambiaron {$usuariosAfectados} usuario(s) de '{$nombreOrigen}' a '{$nombreDestino}'",
                'data' => [
                    'usuarios_afectados' => $usuariosAfectados,
                    'estado_origen' => $nombreOrigen,
                    'estado_destino' => $nombreDestino
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar estados: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de estados
     */
    public function getEstadisticas()
    {
        try {
            $estadisticas = DB::table('tbl_est')
                ->leftJoin('tbl_usu', 'tbl_est.est_id', '=', 'tbl_usu.est_id')
                ->select(
                    'tbl_est.est_id',
                    'tbl_est.est_nom',
                    DB::raw('COUNT(tbl_usu.usu_id) as total_usuarios'),
                    DB::raw('ROUND((COUNT(tbl_usu.usu_id) * 100.0 / NULLIF((SELECT COUNT(*) FROM tbl_usu), 0)), 2) as porcentaje')
                )
                ->groupBy('tbl_est.est_id', 'tbl_est.est_nom')
                ->orderBy('total_usuarios', 'desc')
                ->get();

            $totalUsuarios = DB::table('tbl_usu')->count();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'estadisticas' => $estadisticas,
                    'total_usuarios_sistema' => $totalUsuarios
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }
}