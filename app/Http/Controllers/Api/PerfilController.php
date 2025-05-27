<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Perfil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PerfilController extends Controller
{
    /**
     * Obtener todos los perfiles con información adicional
     */
    public function index(Request $request)
    {
        try {
            $search = $request->get('search', '');
            $perPage = $request->get('per_page', 15);

            $query = DB::table('tbl_per')
                ->select(
                    'tbl_per.per_id',
                    'tbl_per.per_nom',
                    DB::raw('COUNT(DISTINCT tbl_usu.usu_id) as usuarios_count'),
                    DB::raw('COUNT(DISTINCT tbl_perm.perm_id) as permisos_count')
                )
                ->leftJoin('tbl_usu', 'tbl_per.per_id', '=', 'tbl_usu.per_id')
                ->leftJoin('tbl_perm', 'tbl_per.per_id', '=', 'tbl_perm.per_id');

            if (!empty($search)) {
                $query->where('tbl_per.per_nom', 'ILIKE', "%{$search}%");
            }

            $perfiles = $query->groupBy('tbl_per.per_id', 'tbl_per.per_nom')
                            ->orderBy('tbl_per.per_id')
                            ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $perfiles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener perfiles: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear nuevo perfil
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_nom' => 'required|string|max:100|unique:tbl_per,per_nom'
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

            $perfil = Perfil::create([
                'per_nom' => $request->per_nom
            ]);

            // Obtener el perfil creado con contadores
            $perfilCompleto = DB::table('tbl_per')
                ->select(
                    'tbl_per.per_id',
                    'tbl_per.per_nom',
                    DB::raw('0 as usuarios_count'),
                    DB::raw('0 as permisos_count')
                )
                ->where('tbl_per.per_id', $perfil->per_id)
                ->first();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Perfil creado exitosamente',
                'data' => $perfilCompleto
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear perfil: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener perfil específico
     */
    public function show($id)
    {
        try {
            $perfil = DB::table('tbl_per')
                ->select(
                    'tbl_per.per_id',
                    'tbl_per.per_nom',
                    DB::raw('COUNT(DISTINCT tbl_usu.usu_id) as usuarios_count'),
                    DB::raw('COUNT(DISTINCT tbl_perm.perm_id) as permisos_count')
                )
                ->leftJoin('tbl_usu', 'tbl_per.per_id', '=', 'tbl_usu.per_id')
                ->leftJoin('tbl_perm', 'tbl_per.per_id', '=', 'tbl_perm.per_id')
                ->where('tbl_per.per_id', $id)
                ->groupBy('tbl_per.per_id', 'tbl_per.per_nom')
                ->first();

            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $perfil
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener perfil: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar perfil
     */
    public function update(Request $request, $id)
    {
        $perfil = Perfil::find($id);
        
        if (!$perfil) {
            return response()->json([
                'status' => 'error',
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'per_nom' => 'required|string|max:100|unique:tbl_per,per_nom,' . $id . ',per_id'
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

            $perfil->update([
                'per_nom' => $request->per_nom
            ]);

            // Obtener el perfil actualizado con contadores
            $perfilCompleto = DB::table('tbl_per')
                ->select(
                    'tbl_per.per_id',
                    'tbl_per.per_nom',
                    DB::raw('COUNT(DISTINCT tbl_usu.usu_id) as usuarios_count'),
                    DB::raw('COUNT(DISTINCT tbl_perm.perm_id) as permisos_count')
                )
                ->leftJoin('tbl_usu', 'tbl_per.per_id', '=', 'tbl_usu.per_id')
                ->leftJoin('tbl_perm', 'tbl_per.per_id', '=', 'tbl_perm.per_id')
                ->where('tbl_per.per_id', $id)
                ->groupBy('tbl_per.per_id', 'tbl_per.per_nom')
                ->first();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Perfil actualizado exitosamente',
                'data' => $perfilCompleto
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar perfil: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar perfil
     */
    public function destroy($id)
    {
        try {
            $perfil = Perfil::find($id);
            
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // Verificar si hay usuarios asignados a este perfil
            $usuariosCount = DB::table('tbl_usu')
                ->where('per_id', $id)
                ->count();

            if ($usuariosCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No se puede eliminar el perfil porque tiene {$usuariosCount} usuario(s) asignado(s)"
                ], 400);
            }

            DB::beginTransaction();

            // Eliminar permisos asociados
            DB::table('tbl_perm')->where('per_id', $id)->delete();
            
            // Eliminar perfil
            $perfil->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Perfil eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar perfil: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener usuarios de un perfil específico
     */
    public function getUsuarios($id)
    {
        try {
            $perfil = Perfil::find($id);
            
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            $usuarios = DB::table('tbl_usu')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->select(
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_nom2',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_ape2',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'tbl_est.est_nom as estado',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
                )
                ->where('tbl_usu.per_id', $id)
                ->orderBy('tbl_usu.usu_nom')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'perfil' => $perfil,
                    'usuarios' => $usuarios,
                    'total_usuarios' => $usuarios->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuarios del perfil: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Duplicar perfil con sus permisos
     */
    public function duplicate(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nuevo_nombre' => 'required|string|max:100|unique:tbl_per,per_nom'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $perfilOriginal = Perfil::find($id);
            
            if (!$perfilOriginal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil original no encontrado'
                ], 404);
            }

            DB::beginTransaction();

            // Crear nuevo perfil
            $nuevoPerfil = Perfil::create([
                'per_nom' => $request->nuevo_nombre
            ]);

            // Copiar permisos del perfil original
            $permisosOriginales = DB::table('tbl_perm')
                ->where('per_id', $id)
                ->get();

            foreach ($permisosOriginales as $permiso) {
                DB::table('tbl_perm')->insert([
                    'per_id' => $nuevoPerfil->per_id,
                    'men_id' => $permiso->men_id,
                    'sub_id' => $permiso->sub_id,
                    'opc_id' => $permiso->opc_id
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Perfil duplicado exitosamente. Se copiaron {$permisosOriginales->count()} permisos",
                'data' => [
                    'nuevo_perfil_id' => $nuevoPerfil->per_id,
                    'nuevo_perfil_nombre' => $nuevoPerfil->per_nom,
                    'permisos_copiados' => $permisosOriginales->count()
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al duplicar perfil: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de permisos del perfil
     */
    public function getPermissionsSummary($id)
    {
        try {
            $perfil = Perfil::find($id);
            
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // Contar permisos por tipo
            $resumenPermisos = [
                'menus' => DB::table('tbl_perm')
                    ->where('per_id', $id)
                    ->whereNull('sub_id')
                    ->whereNull('opc_id')
                    ->count(),
                'submenus' => DB::table('tbl_perm')
                    ->where('per_id', $id)
                    ->whereNotNull('sub_id')
                    ->whereNull('opc_id')
                    ->count(),
                'opciones' => DB::table('tbl_perm')
                    ->where('per_id', $id)
                    ->whereNotNull('opc_id')
                    ->count()
            ];

            $resumenPermisos['total'] = $resumenPermisos['menus'] + 
                                       $resumenPermisos['submenus'] + 
                                       $resumenPermisos['opciones'];

            return response()->json([
                'status' => 'success',
                'data' => [
                    'perfil' => $perfil,
                    'resumen_permisos' => $resumenPermisos
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener resumen de permisos: ' . $e->getMessage()
            ], 500);
        }
    }
}