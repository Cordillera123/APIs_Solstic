<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Perfil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class PerfilController extends Controller
{
    /**
     * Display a listing of the resource.
     * ✅ CORRECCIÓN: Devolver solo los elementos, no la paginación completa
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');
            $activo = $request->get('activo', '');
            $nivel = $request->get('nivel', '');

            $query = DB::table('tbl_per as p')
                ->leftJoin(
                    DB::raw('(SELECT per_id, COUNT(*) as total_usuarios FROM tbl_usu WHERE per_id IS NOT NULL GROUP BY per_id) as usuarios'),
                    'p.per_id', '=', 'usuarios.per_id'
                )
                ->leftJoin(
                    DB::raw('(SELECT per_id, COUNT(*) as total_permisos FROM tbl_perm WHERE per_id IS NOT NULL GROUP BY per_id) as permisos'),
                    'p.per_id', '=', 'permisos.per_id'
                )
                ->select([
                    'p.per_id',
                    'p.per_nom',
                    'p.per_descripcion',
                    'p.per_nivel',
                    'p.per_activo',
                    'p.per_cre',
                    'p.per_edi',
                    DB::raw('COALESCE(usuarios.total_usuarios, 0) as usuarios_count'), // ✅ Cambiar nombre para consistencia
                    DB::raw('COALESCE(permisos.total_permisos, 0) as total_permisos')
                ]);

            // Filtros
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('p.per_nom', 'ILIKE', "%{$search}%")
                      ->orWhere('p.per_descripcion', 'ILIKE', "%{$search}%");
                });
            }

            if (!empty($activo)) {
                $query->where('p.per_activo', $request->boolean('activo'));
            }

            if (!empty($nivel)) {
                $query->where('p.per_nivel', $nivel);
            }

            // Ordenamiento
            $sortField = $request->get('sort_field', 'per_nom');
            $sortDirection = $request->get('sort_direction', 'asc');
            $query->orderBy($sortField, $sortDirection);

            $perfiles = $query->paginate($perPage);

            // ✅ CORRECCIÓN PRINCIPAL: Devolver solo los elementos
            return response()->json([
                'status' => 'success',
                'message' => 'Perfiles obtenidos correctamente',
                'data' => $perfiles->items(), // ✅ Solo los elementos, no la paginación completa
                'pagination' => [
                    'total' => $perfiles->total(),
                    'current_page' => $perfiles->currentPage(),
                    'per_page' => $perfiles->perPage(),
                    'last_page' => $perfiles->lastPage(),
                    'from' => $perfiles->firstItem(),
                    'to' => $perfiles->lastItem()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener perfiles: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_nom' => 'required|string|max:100|unique:tbl_per,per_nom',
            'per_descripcion' => 'nullable|string|max:500',
            'per_nivel' => 'required|integer|min:1|max:10',
            'per_activo' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        try {
            DB::beginTransaction();

            $perfilData = $request->only([
                'per_nom', 'per_descripcion', 'per_nivel'
            ]);
            
            $perfilData['per_activo'] = $request->boolean('per_activo', true);
            $perfilData['per_cre'] = Carbon::now();
            $perfilData['per_edi'] = Carbon::now();

            $perfil = Perfil::create($perfilData);

            // Obtener el perfil creado con contadores
            $perfilCompleto = $this->getPerfilCompleto($perfil->per_id);

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
                'message' => 'Error al crear perfil: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $perfil = $this->getPerfilCompleto($id);

            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Perfil obtenido correctamente',
                'data' => $perfil
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener perfil: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $perfil = Perfil::find($id);
        
        if (!$perfil) {
            return response()->json([
                'status' => 'error',
                'message' => 'Perfil no encontrado',
                'data' => null
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'per_nom' => 'sometimes|required|string|max:100|unique:tbl_per,per_nom,' . $id . ',per_id',
            'per_descripcion' => 'nullable|string|max:500',
            'per_nivel' => 'sometimes|required|integer|min:1|max:10',
            'per_activo' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        try {
            DB::beginTransaction();

            $perfilData = $request->only([
                'per_nom', 'per_descripcion', 'per_nivel'
            ]);
            
            if ($request->has('per_activo')) {
                $perfilData['per_activo'] = $request->boolean('per_activo');
            }
            
            $perfilData['per_edi'] = Carbon::now();

            $perfil->update($perfilData);

            // Obtener el perfil actualizado con contadores
            $perfilCompleto = $this->getPerfilCompleto($id);

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
                'message' => 'Error al actualizar perfil: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $perfil = Perfil::find($id);
            
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado',
                    'data' => null
                ], 404);
            }

            // Verificar si hay usuarios asignados a este perfil
            $usuariosCount = DB::table('tbl_usu')
                ->where('per_id', $id)
                ->count();

            if ($usuariosCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No se puede eliminar el perfil porque tiene {$usuariosCount} usuario(s) asignado(s)",
                    'data' => null
                ], 400);
            }

            DB::beginTransaction();

            // Guardar información antes de eliminar
            $perfilInfo = [
                'per_id' => $perfil->per_id,
                'per_nom' => $perfil->per_nom,
                'per_descripcion' => $perfil->per_descripcion
            ];

            // Eliminar permisos asociados
            DB::table('tbl_perm')->where('per_id', $id)->delete();
            DB::table('tbl_perm_perfil')->where('per_id', $id)->delete();
            
            // Eliminar perfil
            $perfil->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Perfil eliminado exitosamente',
                'data' => $perfilInfo
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar perfil: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Toggle profile status (active/inactive)
     */
    public function toggleStatus($id)
    {
        try {
            $perfil = Perfil::find($id);
            
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            $nuevoEstado = !$perfil->per_activo;
            $perfil->per_activo = $nuevoEstado;
            $perfil->per_edi = Carbon::now();
            $perfil->save();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $nuevoEstado ? 'Perfil activado exitosamente' : 'Perfil desactivado exitosamente',
                'data' => [
                    'per_id' => $perfil->per_id,
                    'per_activo' => $nuevoEstado,
                    'per_nom' => $perfil->per_nom
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar estado del perfil: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get users assigned to a specific profile
     */
    public function getUsuarios($id)
    {
        try {
            $perfil = Perfil::find($id);
            
            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado',
                    'data' => null
                ], 404);
            }

            $usuarios = DB::table('tbl_usu')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->select([
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_nom2',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_ape2',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'tbl_usu.usu_deshabilitado',
                    'tbl_usu.usu_fecha_registro',
                    'tbl_usu.usu_ultimo_acceso',
                    'tbl_est.est_nom as estado',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
                ])
                ->where('tbl_usu.per_id', $id)
                ->orderBy('tbl_usu.usu_nom')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuarios del perfil obtenidos correctamente',
                'data' => [
                    'perfil' => [
                        'per_id' => $perfil->per_id,
                        'per_nom' => $perfil->per_nom,
                        'per_descripcion' => $perfil->per_descripcion,
                        'per_nivel' => $perfil->per_nivel,
                        'per_activo' => $perfil->per_activo
                    ],
                    'usuarios' => $usuarios,
                    'total_usuarios' => $usuarios->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuarios del perfil: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get form options for profile creation/editing
     */
    public function getFormOptions()
    {
        try {
            $niveles = [];
            for ($i = 1; $i <= 10; $i++) {
                $niveles[] = [
                    'value' => $i,
                    'label' => "Nivel {$i}"
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Opciones obtenidas correctamente',
                'data' => [
                    'niveles' => $niveles
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener opciones: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Duplicate profile with its permissions
     */
    public function duplicate(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'per_nom' => 'required|string|max:100|unique:tbl_per,per_nom',
            'per_descripcion' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de validación incorrectos',
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        try {
            $perfilOriginal = Perfil::find($id);
            
            if (!$perfilOriginal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil original no encontrado',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            // Crear nuevo perfil
            $nuevoPerfil = Perfil::create([
                'per_nom' => $request->per_nom,
                'per_descripcion' => $request->per_descripcion ?? $perfilOriginal->per_descripcion,
                'per_nivel' => $perfilOriginal->per_nivel,
                'per_activo' => true,
                'per_cre' => Carbon::now(),
                'per_edi' => Carbon::now()
            ]);

            // Copiar permisos del perfil original
            $permisosOriginales = DB::table('tbl_perm')
                ->where('per_id', $id)
                ->get();

            $permisosCopiadosCount = 0;
            foreach ($permisosOriginales as $permiso) {
                DB::table('tbl_perm')->insert([
                    'per_id' => $nuevoPerfil->per_id,
                    'men_id' => $permiso->men_id,
                    'sub_id' => $permiso->sub_id,
                    'opc_id' => $permiso->opc_id
                ]);
                $permisosCopiadosCount++;
            }

            // Copiar permisos de perfil específicos si existen
            $permisosPerfilOriginales = DB::table('tbl_perm_perfil')
                ->where('per_id', $id)
                ->get();

            foreach ($permisosPerfilOriginales as $permiso) {
                DB::table('tbl_perm_perfil')->insert([
                    'per_id' => $nuevoPerfil->per_id,
                    'men_id' => $permiso->men_id,
                    'sub_id' => $permiso->sub_id,
                    'opc_id' => $permiso->opc_id,
                    'perm_per_activo' => $permiso->perm_per_activo,
                    'perm_per_cre' => Carbon::now(),
                    'perm_per_edi' => Carbon::now()
                ]);
            }

            $perfilCompleto = $this->getPerfilCompleto($nuevoPerfil->per_id);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Perfil duplicado exitosamente. Se copiaron {$permisosCopiadosCount} permisos",
                'data' => $perfilCompleto
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al duplicar perfil: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get complete profile information
     * ✅ CORRECCIÓN: Asegurar que devuelva usuarios_count para consistencia con frontend
     */
    private function getPerfilCompleto($id)
    {
        return DB::table('tbl_per as p')
            ->leftJoin(
                DB::raw('(SELECT per_id, COUNT(*) as total_usuarios FROM tbl_usu WHERE per_id IS NOT NULL GROUP BY per_id) as usuarios'),
                'p.per_id', '=', 'usuarios.per_id'
            )
            ->leftJoin(
                DB::raw('(SELECT per_id, COUNT(*) as total_permisos FROM tbl_perm WHERE per_id IS NOT NULL GROUP BY per_id) as permisos'),
                'p.per_id', '=', 'permisos.per_id'
            )
            ->where('p.per_id', $id)
            ->select([
                'p.per_id',
                'p.per_nom',
                'p.per_descripcion',
                'p.per_nivel',
                'p.per_activo',
                'p.per_cre',
                'p.per_edi',
                DB::raw('COALESCE(usuarios.total_usuarios, 0) as usuarios_count'), // ✅ Nombre consistente
                DB::raw('COALESCE(permisos.total_permisos, 0) as total_permisos')
            ])
            ->first();
    }
}