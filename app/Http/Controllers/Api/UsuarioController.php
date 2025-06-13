<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class UsuarioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');
            $perfilId = $request->get('perfil_id', '');
            $estadoId = $request->get('estado_id', '');
            $activo = $request->get('activo', '');

            $query = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->leftJoin('tbl_usu as creador', 'tbl_usu.usu_creado_por', '=', 'creador.usu_id')
                ->select(
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_nom2',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_ape2',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'tbl_usu.usu_tel',
                    'tbl_usu.usu_dir',
                    'tbl_usu.usu_descripcion',
                    'tbl_usu.usu_fecha_nacimiento',
                    'tbl_usu.usu_fecha_registro',
                    'tbl_usu.usu_ultimo_acceso',
                    'tbl_usu.usu_intentos_fallidos',
                    'tbl_usu.usu_bloqueado_hasta',
                    'tbl_usu.usu_deshabilitado',
                    'tbl_per.per_nom as perfil',
                    'tbl_est.est_nom as estado',
                    'tbl_usu.per_id',
                    'tbl_usu.est_id',
                    'creador.usu_nom as creado_por_nombre',
                    'tbl_usu.usu_cre',
                    'tbl_usu.usu_edi',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
                );

            // Filtros
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('tbl_usu.usu_nom', 'ILIKE', "%{$search}%")
                      ->orWhere('tbl_usu.usu_ape', 'ILIKE', "%{$search}%")
                      ->orWhere('tbl_usu.usu_cor', 'ILIKE', "%{$search}%")
                      ->orWhere('tbl_usu.usu_ced', 'ILIKE', "%{$search}%")
                      ->orWhere('tbl_per.per_nom', 'ILIKE', "%{$search}%");
                });
            }

            if (!empty($perfilId)) {
                $query->where('tbl_usu.per_id', $perfilId);
            }

            if (!empty($estadoId)) {
                $query->where('tbl_usu.est_id', $estadoId);
            }

            if (!empty($activo)) {
                $query->where('tbl_usu.usu_deshabilitado', !$request->boolean('activo'));
            }

            $usuarios = $query->orderBy('tbl_usu.usu_fecha_registro', 'desc')
                            ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $usuarios
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuarios: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // 🔍 Imprimir datos recibidos ANTES de validación
        \Log::info("📧 Correo antes de validar:", ['email' => $request->input('usu_cor')]);
        \Log::info("🔒 Contraseña antes de validar:", ['password' => $request->input('usu_con')]);


        $validator = Validator::make($request->all(), [
            'usu_nom' => 'required|string|max:100',
            'usu_nom2' => 'nullable|string|max:100',
            'usu_ape' => 'required|string|max:100',
            'usu_ape2' => 'nullable|string|max:100',
            'usu_cor' => 'required|email|unique:tbl_usu,usu_cor|max:100',
            'usu_ced' => 'required|string|unique:tbl_usu,usu_ced|max:10',
            'usu_con' => 'required|string|min:6|max:64',
            'usu_tel' => 'nullable|string|max:10',
            'usu_dir' => 'nullable|string|max:100',
            'per_id' => 'required|integer|exists:tbl_per,per_id',
            'est_id' => 'required|integer|exists:tbl_est,est_id',
            'usu_descripcion' => 'nullable|string',
            'usu_fecha_nacimiento' => 'nullable|date|before:today'
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

            $usuarioData = $request->only([
                'usu_nom', 'usu_nom2', 'usu_ape', 'usu_ape2', 'usu_cor', 'usu_ced',
                'usu_tel', 'usu_dir', 'per_id', 'est_id', 'usu_descripcion', 'usu_fecha_nacimiento'
            ]);
            
            // Hashear la contraseña
<<<<<<< Updated upstream
            $usuarioData['usu_con'] = Hash::make($request->usu_con);
            
=======
            $usuarioData['usu_con'] = $request->usu_con;

>>>>>>> Stashed changes
            // Campos adicionales
            $usuarioData['usu_fecha_registro'] = Carbon::now();
            $usuarioData['usu_deshabilitado'] = false;
            $usuarioData['usu_intentos_fallidos'] = 0;
            
            // Si hay un usuario autenticado, asignar como creador
            try {
                if (Auth::check() && Auth::user()) {
                    $usuarioData['usu_creado_por'] = Auth::user()->usu_id;
                    $usuarioData['usu_editado_por'] = Auth::user()->usu_id;
                }
            } catch (\Exception $e) {
                // Si hay error con la autenticación, continuar sin asignar creador
            }

            \Log::info("📧 Correo antes de guardar:", ['email' => $usuarioData['usu_cor']]);
            \Log::info("🔒 Contraseña antes de guardar:", ['password' => $usuarioData['usu_con']]);

            $usuario = Usuario::create($usuarioData);

            \Log::info("📥 Usuario creado con éxito:", [
                'email' => $usuario->usu_cor,
                'contraseña' => $usuario->usu_con
            ]);

            // Obtener el usuario creado con relaciones
            $usuarioCompleto = $this->getUsuarioCompleto($usuario->usu_id);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario creado exitosamente',
                'data' => $usuarioCompleto
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $usuario = $this->getUsuarioCompleto($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Ocultar contraseña
            unset($usuario->usu_con);

            return response()->json([
                'status' => 'success',
                'data' => $usuario
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $usuario = Usuario::find($id);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'usu_nom' => 'sometimes|required|string|max:100',
            'usu_nom2' => 'nullable|string|max:100',
            'usu_ape' => 'sometimes|required|string|max:100',
            'usu_ape2' => 'nullable|string|max:100',
            'usu_cor' => 'sometimes|required|email|unique:tbl_usu,usu_cor,' . $id . ',usu_id|max:100',
            'usu_ced' => 'sometimes|required|string|unique:tbl_usu,usu_ced,' . $id . ',usu_id|max:10',
            'usu_con' => 'nullable|string|min:6|max:64',
            'usu_tel' => 'nullable|string|max:10',
            'usu_dir' => 'nullable|string|max:100',
            'per_id' => 'sometimes|required|integer|exists:tbl_per,per_id',
            'est_id' => 'sometimes|required|integer|exists:tbl_est,est_id',
            'usu_descripcion' => 'nullable|string',
            'usu_fecha_nacimiento' => 'nullable|date|before:today'
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

            $usuarioData = $request->only([
                'usu_nom', 'usu_nom2', 'usu_ape', 'usu_ape2', 'usu_cor', 'usu_ced',
                'usu_tel', 'usu_dir', 'per_id', 'est_id', 'usu_descripcion', 'usu_fecha_nacimiento'
            ]);
            
            // Si se proporciona nueva contraseña, hashearla
            if (!empty($request->usu_con)) {
                $usuarioData['usu_con'] = ($request->usu_con);
            }

            $usuario->update($usuarioData);

            // Obtener el usuario actualizado con relaciones
            $usuarioCompleto = $this->getUsuarioCompleto($id);

            // Ocultar contraseña
            unset($usuarioCompleto->usu_con);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario actualizado exitosamente',
                'data' => $usuarioCompleto
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            DB::beginTransaction();

            // Verificar si el usuario tiene dependencias
            $tienePermisos = DB::table('tbl_perm_usuario')->where('usu_id', $id)->exists();
            $tienePermisosUsuario = DB::table('tbl_usu_perm')->where('usu_id', $id)->exists();
            
            if ($tienePermisos || $tienePermisosUsuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede eliminar el usuario porque tiene permisos asignados'
                ], 400);
            }

            // Eliminar tokens de acceso del usuario
            $usuario->tokens()->delete();
            
            // Eliminar usuario
            $usuario->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle user status (enable/disable)
     */
    public function toggleStatus($id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            DB::beginTransaction();

            // Alternar estado de deshabilitado
            $nuevoEstado = !$usuario->usu_deshabilitado;
            $usuario->usu_deshabilitado = $nuevoEstado;
            $usuario->save();

            // Si se desactiva el usuario, revocar todos sus tokens
            if ($nuevoEstado) {
                $usuario->tokens()->delete();
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $nuevoEstado ? 'Usuario deshabilitado exitosamente' : 'Usuario habilitado exitosamente',
                'data' => [
                    'usu_deshabilitado' => $nuevoEstado
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change user password
     */
<<<<<<< Updated upstream
    public function changePassword(Request $request, $id)
=======
    public function getPermissionsDetail($id)
    {
        try {
            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            Log::info("🔍 Obteniendo permisos detallados para usuario {$id}");

            // Obtener permisos del perfil del usuario desde tbl_perm_perfil
            $permisosPerfil = DB::table('tbl_perm_perfil')
                ->join('tbl_men', 'tbl_perm_perfil.men_id', '=', 'tbl_men.men_id')
                ->leftJoin('tbl_sub', 'tbl_perm_perfil.sub_id', '=', 'tbl_sub.sub_id')
                ->leftJoin('tbl_opc', 'tbl_perm_perfil.opc_id', '=', 'tbl_opc.opc_id')
                ->leftJoin('tbl_ico as ico_men', 'tbl_men.ico_id', '=', 'ico_men.ico_id')
                ->leftJoin('tbl_ico as ico_sub', 'tbl_sub.ico_id', '=', 'ico_sub.ico_id')
                ->leftJoin('tbl_ico as ico_opc', 'tbl_opc.ico_id', '=', 'ico_opc.ico_id')
                ->where('tbl_perm_perfil.per_id', $usuario->per_id)
                ->where('tbl_perm_perfil.perm_per_activo', true)
                ->where('tbl_men.men_activo', true)
                ->select(
                    'tbl_perm_perfil.men_id',
                    'tbl_perm_perfil.sub_id',
                    'tbl_perm_perfil.opc_id',
                    'tbl_men.men_nom',
                    'tbl_men.men_componente',
                    'ico_men.ico_nom as men_icon_nombre',
                    'tbl_sub.sub_nom',
                    'tbl_sub.sub_componente',
                    'tbl_sub.sub_activo',
                    'ico_sub.ico_nom as sub_icon_nombre',
                    'tbl_opc.opc_nom',
                    'tbl_opc.opc_componente',
                    'tbl_opc.opc_activo',
                    'ico_opc.ico_nom as opc_icon_nombre'
                )
                ->get();

            // ✅ SIMPLIFICADO: Obtener permisos individuales usando solo tbl_usu_perm
            $permisosUsuario = DB::table('tbl_usu_perm')
                ->where('usu_id', $id)
                ->get();

            Log::info("📊 Permisos encontrados: perfil={$permisosPerfil->count()}, usuario={$permisosUsuario->count()}");

            // Crear un Set de permisos que el usuario tiene activos
            $permisosActivosUsuario = $permisosUsuario->mapWithKeys(function ($item) {
                $key = $item->men_id . '-' . ($item->sub_id ?? 'null') . '-' . ($item->opc_id ?? 'null');
                return [$key => true];
            });

            // Organizar permisos del perfil en estructura de árbol
            $menuTree = [];

            foreach ($permisosPerfil as $item) {
                $permisoKey = $item->men_id . '-' . ($item->sub_id ?? 'null') . '-' . ($item->opc_id ?? 'null');
                $usuarioTienePermiso = isset($permisosActivosUsuario[$permisoKey]);

                // Crear menú si no existe
                if (!isset($menuTree[$item->men_id])) {
                    $menuTree[$item->men_id] = [
                        'men_id' => $item->men_id,
                        'men_nom' => $item->men_nom,
                        'men_componente' => $item->men_componente,
                        'ico_nombre' => $item->men_icon_nombre,
                        'has_permission' => $item->sub_id === null && $item->opc_id === null ? $usuarioTienePermiso : false,
                        'submenus' => []
                    ];
                }

                // Agregar submenú si existe y está activo
                if ($item->sub_id && $item->sub_activo) {
                    $submenuKey = $item->sub_id;

                    if (!isset($menuTree[$item->men_id]['submenus'][$submenuKey])) {
                        $menuTree[$item->men_id]['submenus'][$submenuKey] = [
                            'sub_id' => $item->sub_id,
                            'sub_nom' => $item->sub_nom,
                            'sub_componente' => $item->sub_componente,
                            'ico_nombre' => $item->sub_icon_nombre,
                            'has_permission' => $item->opc_id === null ? $usuarioTienePermiso : false,
                            'opciones' => []
                        ];
                    }

                    // Agregar opción si existe y está activa
                    if ($item->opc_id && $item->opc_activo) {
                        $menuTree[$item->men_id]['submenus'][$submenuKey]['opciones'][] = [
                            'opc_id' => $item->opc_id,
                            'opc_nom' => $item->opc_nom,
                            'opc_componente' => $item->opc_componente,
                            'ico_nombre' => $item->opc_icon_nombre,
                            'has_permission' => $usuarioTienePermiso
                        ];
                    }
                }
            }

            // Convertir submenus de asociativo a indexado
            foreach ($menuTree as &$menu) {
                $menu['submenus'] = array_values($menu['submenus']);
            }

            Log::info("✅ Estructura de permisos construida exitosamente");

            return response()->json([
                'status' => 'success',
                'message' => 'Permisos del usuario obtenidos correctamente',
                'data' => [
                    'usuario' => [
                        'usu_id' => $usuario->usu_id,
                        'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_nom2} {$usuario->usu_ape} {$usuario->usu_ape2}"),
                        'usu_cor' => $usuario->usu_cor,
                        'per_id' => $usuario->per_id
                    ],
                    'permisos' => array_values($menuTree),
                    'permisos_usuario_activos' => $permisosUsuario->count(),
                    'total_permisos_disponibles' => $permisosPerfil->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error en getPermissionsDetail: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos del usuario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * OPCIONAL: Método para limpiar registros problemáticos de tbl_perm_usuario
     */
    public function cleanupBrokenPermissions()
    {
        try {
            // Contar registros problemáticos
            $brokenCount = DB::table('tbl_perm_usuario')
                ->where('perm_tipo', 'NOT IN', DB::raw("('C'::bpchar, 'D'::bpchar)"))
                ->count();

            Log::info("🧹 Encontrados {$brokenCount} registros problemáticos en tbl_perm_usuario");

            if ($brokenCount > 0) {
                // Opcional: Eliminar registros problemáticos
                // DB::table('tbl_perm_usuario')
                //     ->where('perm_tipo', 'NOT IN', DB::raw("('C'::bpchar, 'D'::bpchar)"))
                //     ->delete();
            }

            return response()->json([
                'status' => 'success',
                'message' => "Encontrados {$brokenCount} registros problemáticos",
                'broken_records' => $brokenCount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * ✅ RESTAURAR: Endpoin
     *  para obtener permisos (para AsgiPerUsWindows)
     */
    public function getPermissions($id)
    {
        return $this->getPermissionsDetail($id);
    }

    /**
     * Asignar permisos específicos a un usuario
     */
    public function assignPermissions(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*.men_id' => 'required|integer|exists:tbl_men,men_id',
            'permissions.*.sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'permissions.*.opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
            'permissions.*.grant' => 'required|boolean'
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
            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            Log::info("🔧 Iniciando asignación de permisos para usuario {$id}");

            DB::beginTransaction();

            $processedCount = 0;
            $errores = [];

            foreach ($request->permissions as $permission) {
                try {
                    $menId = $permission['men_id'];
                    $subId = $permission['sub_id'];
                    $opcId = $permission['opc_id'];
                    $grant = $permission['grant'];

                    Log::info("🔍 Procesando permiso: menú={$menId}, sub={$subId}, opc={$opcId}, grant={$grant}");

                    // ✅ SIMPLIFICADO: Verificar que el permiso esté disponible en el perfil del usuario
                    $perfilHasPermission = DB::table('tbl_perm_perfil')
                        ->where('per_id', $usuario->per_id)
                        ->where('men_id', $menId)
                        ->where(function ($query) use ($subId) {
                            if ($subId !== null) {
                                $query->where('sub_id', $subId);
                            } else {
                                $query->whereNull('sub_id');
                            }
                        })
                        ->where(function ($query) use ($opcId) {
                            if ($opcId !== null) {
                                $query->where('opc_id', $opcId);
                            } else {
                                $query->whereNull('opc_id');
                            }
                        })
                        ->where('perm_per_activo', true)
                        ->exists();

                    if (!$perfilHasPermission) {
                        $errores[] = "Permiso no disponible en perfil para menú {$menId}";
                        Log::warning("⚠️ Permiso no disponible en perfil: menú={$menId}, sub={$subId}, opc={$opcId}");
                        continue;
                    }

                    // ✅ USAR SOLO TBL_USU_PERM - es más simple y funcional
                    $userPermissionData = [
                        'usu_id' => $id,
                        'men_id' => $menId,
                        'sub_id' => $subId,
                        'opc_id' => $opcId
                    ];

                    // Verificar si ya existe este permiso específico para el usuario
                    $existingUserPermission = DB::table('tbl_usu_perm')
                        ->where($userPermissionData)
                        ->exists();

                    if ($grant && !$existingUserPermission) {
                        // ✅ OTORGAR PERMISO: Insertar en tbl_usu_perm
                        $userPermissionData['created_at'] = now();

                        DB::table('tbl_usu_perm')->insert($userPermissionData);
                        $processedCount++;
                        Log::info("✅ Permiso otorgado: menú={$menId}, sub={$subId}, opc={$opcId}");
                    } elseif (!$grant && $existingUserPermission) {
                        // ✅ REVOCAR PERMISO: Eliminar de tbl_usu_perm
                        DB::table('tbl_usu_perm')->where($userPermissionData)->delete();
                        $processedCount++;
                        Log::info("✅ Permiso revocado: menú={$menId}, sub={$subId}, opc={$opcId}");
                    }
                } catch (\Exception $e) {
                    $errores[] = "Error procesando permiso menú {$menId}: " . $e->getMessage();
                    Log::error("❌ Error procesando permiso: " . $e->getMessage());
                }
            }

            DB::commit();

            $mensaje = "Se procesaron {$processedCount} cambios de permisos correctamente";
            if (!empty($errores) && $processedCount === 0) {
                $mensaje = "No se procesaron cambios. Errores: " . implode(', ', array_slice($errores, 0, 2));
            } elseif (!empty($errores)) {
                $mensaje .= ". Algunos errores: " . implode(', ', array_slice($errores, 0, 1));
            }

            Log::info("✅ Asignación de permisos completada: {$processedCount} cambios procesados");

            return response()->json([
                'status' => $processedCount > 0 ? 'success' : 'warning',
                'message' => $mensaje,
                'data' => [
                    'changes_processed' => $processedCount,
                    'errors' => $errores,
                    'usuario_id' => $id,
                    'perfil_id' => $usuario->per_id,
                    'total_permissions_attempted' => count($request->permissions)
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error general en assignPermissions: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al asignar permisos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener permisos activos de un usuario (combinando perfil + individuales)
     */
    public function getActivePermissions($id)
>>>>>>> Stashed changes
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6|max:64',
                'confirm_password' => 'required|string|same:new_password'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            if (!Hash::check($request->current_password, $usuario->usu_con)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La contraseña actual es incorrecta'
                ], 400);
            }

            DB::beginTransaction();

            $usuario->update([
                'usu_con' => Hash::make($request->new_password),
                'usu_fecha_cambio_clave' => Carbon::now()
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña cambiada exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar contraseña: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset user password (admin action)
     */
    public function resetPassword(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'new_password' => 'required|string|min:6|max:64'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            DB::beginTransaction();

            $usuario->update([
                'usu_con' => Hash::make($request->new_password),
                'usu_fecha_actualizacion_clave' => Carbon::now(),
                'usu_intentos_fallidos' => 0,
                'usu_bloqueado_hasta' => null
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña restablecida exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al restablecer contraseña: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get form options for user creation/editing
     */
    public function getFormOptions()
    {
        try {
            $perfiles = DB::table('tbl_per')
                ->where('per_activo', true)
                ->select('per_id as value', 'per_nom as label')
                ->orderBy('per_nom')
                ->get();

            $estados = DB::table('tbl_est')
                ->where('est_activo', true)
                ->select('est_id as value', 'est_nom as label')
                ->orderBy('est_nom')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'perfiles' => $perfiles,
                    'estados' => $estados
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener opciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get complete user information
     */
    private function getUsuarioCompleto($id)
    {
        return DB::table('tbl_usu')
            ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
            ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
            ->leftJoin('tbl_usu as creador', 'tbl_usu.usu_creado_por', '=', 'creador.usu_id')
            ->leftJoin('tbl_usu as editor', 'tbl_usu.usu_editado_por', '=', 'editor.usu_id')
            ->where('tbl_usu.usu_id', $id)
            ->select(
                'tbl_usu.usu_id',
                'tbl_usu.usu_nom',
                'tbl_usu.usu_nom2',
                'tbl_usu.usu_ape',
                'tbl_usu.usu_ape2',
                'tbl_usu.usu_cor',
                'tbl_usu.usu_ced',
                'tbl_usu.usu_tel',
                'tbl_usu.usu_dir',
                'tbl_usu.per_id',
                'tbl_usu.est_id',
                'tbl_usu.usu_descripcion',
                'tbl_usu.usu_fecha_nacimiento',
                'tbl_usu.usu_fecha_registro',
                'tbl_usu.usu_ultimo_acceso',
                'tbl_usu.usu_intentos_fallidos',
                'tbl_usu.usu_bloqueado_hasta',
                'tbl_usu.usu_deshabilitado',
                'tbl_usu.usu_cre',
                'tbl_usu.usu_edi',
                'tbl_per.per_nom as perfil',
                'tbl_est.est_nom as estado',
                'creador.usu_nom as creado_por_nombre',
                'editor.usu_nom as editado_por_nombre',
                DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_nom2, ''), ' ', COALESCE(tbl_usu.usu_ape, ''), ' ', COALESCE(tbl_usu.usu_ape2, '')) as nombre_completo")
            )
            ->first();
    }

    /**
     * Get complete user information
     */
    


    /**
     * Obtener permisos del usuario
     */
    public function getUserPermissions($id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Usar el método del AuthController para obtener permisos
            $authController = new AuthController();
            $permisos = $authController->getUserMenus($id);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'usuario_id' => $id,
                    'perfil_id' => $usuario->per_id,
                    'permisos' => $permisos
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos: ' . $e->getMessage()
            ], 500);
        }
    }


// Agregar estos métodos al UsuarioController.php existente

/**
 * Obtener permisos detallados de un usuario específico
 */
public function getPermissionsDetail($id)
{
    try {
        $usuario = Usuario::find($id);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Obtener permisos del perfil del usuario
        $permisosPerfil = DB::table('tbl_perm')
            ->join('tbl_men', 'tbl_perm.men_id', '=', 'tbl_men.men_id')
            ->leftJoin('tbl_sub', 'tbl_perm.sub_id', '=', 'tbl_sub.sub_id')
            ->leftJoin('tbl_opc', 'tbl_perm.opc_id', '=', 'tbl_opc.opc_id')
            ->leftJoin('tbl_ico as ico_men', 'tbl_men.ico_id', '=', 'ico_men.ico_id')
            ->leftJoin('tbl_ico as ico_sub', 'tbl_sub.ico_id', '=', 'ico_sub.ico_id')
            ->leftJoin('tbl_ico as ico_opc', 'tbl_opc.ico_id', '=', 'ico_opc.ico_id')
            ->where('tbl_perm.per_id', $usuario->per_id)
            ->where('tbl_men.men_est', true)
            ->select(
                'tbl_men.men_id', 'tbl_men.men_nom', 'tbl_men.men_componente',
                'ico_men.ico_nom as men_icon_nombre',
                'tbl_sub.sub_id', 'tbl_sub.sub_nom', 'tbl_sub.sub_componente', 'tbl_sub.sub_est',
                'ico_sub.ico_nom as sub_icon_nombre',
                'tbl_opc.opc_id', 'tbl_opc.opc_nom', 'tbl_opc.opc_componente', 'tbl_opc.opc_est',
                'ico_opc.ico_nom as opc_icon_nombre'
            )
            ->get();

        // Obtener permisos individuales del usuario (si los hubiera)
        $permisosUsuario = DB::table('tbl_usu_perm')
            ->where('usu_id', $id)
            ->get();

        // Organizar permisos del perfil en estructura de árbol
        $menuTree = [];
        
        foreach ($permisosPerfil as $item) {
            // Crear menú si no existe
            if (!isset($menuTree[$item->men_id])) {
                $menuTree[$item->men_id] = [
                    'men_id' => $item->men_id,
                    'men_nom' => $item->men_nom,
                    'men_componente' => $item->men_componente,
                    'ico_nombre' => $item->men_icon_nombre,
                    'has_permission' => true, // Siempre true porque viene de permisos del perfil
                    'submenus' => []
                ];
            }
            
            // Agregar submenú si existe y está activo
            if ($item->sub_id && $item->sub_est) {
                $submenuKey = $item->sub_id;
                
                if (!isset($menuTree[$item->men_id]['submenus'][$submenuKey])) {
                    $menuTree[$item->men_id]['submenus'][$submenuKey] = [
                        'sub_id' => $item->sub_id,
                        'sub_nom' => $item->sub_nom,
                        'sub_componente' => $item->sub_componente,
                        'ico_nombre' => $item->sub_icon_nombre,
                        'has_permission' => true,
                        'opciones' => []
                    ];
                }
                
                // Agregar opción si existe y está activa
                if ($item->opc_id && $item->opc_est) {
                    $menuTree[$item->men_id]['submenus'][$submenuKey]['opciones'][] = [
                        'opc_id' => $item->opc_id,
                        'opc_nom' => $item->opc_nom,
                        'opc_componente' => $item->opc_componente,
                        'ico_nombre' => $item->opc_icon_nombre,
                        'has_permission' => true
                    ];
                }
            }
        }

        // Convertir submenus de asociativo a indexado
        foreach ($menuTree as &$menu) {
            $menu['submenus'] = array_values($menu['submenus']);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'usuario' => [
                    'usu_id' => $usuario->usu_id,
                    'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_nom2} {$usuario->usu_ape} {$usuario->usu_ape2}"),
                    'usu_cor' => $usuario->usu_cor,
                    'per_id' => $usuario->per_id,
                    'perfil' => $usuario->perfil ? $usuario->perfil->per_nom : null
                ],
                'permisos_perfil' => array_values($menuTree),
                'permisos_usuario' => $permisosUsuario
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener permisos del usuario: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Asignar permisos específicos a un usuario
 */
public function assignPermissions(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'permissions' => 'required|array',
        'permissions.*.men_id' => 'required|integer|exists:tbl_men,men_id',
        'permissions.*.sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
        'permissions.*.opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
        'permissions.*.grant' => 'required|boolean'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Datos de validación incorrectos',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $usuario = Usuario::find($id);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        DB::beginTransaction();

        $processedCount = 0;
        
        foreach ($request->permissions as $permission) {
            $menId = $permission['men_id'];
            $subId = $permission['sub_id'];
            $opcId = $permission['opc_id'];
            $grant = $permission['grant'];

            // Verificar que el permiso esté disponible en el perfil del usuario
            $perfilHasPermission = DB::table('tbl_perm')
                ->where('per_id', $usuario->per_id)
                ->where('men_id', $menId)
                ->where('sub_id', $subId)
                ->where('opc_id', $opcId)
                ->exists();

            if (!$perfilHasPermission) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede asignar un permiso que no está disponible en el perfil del usuario'
                ], 400);
            }

            // Como los permisos de usuario son un subconjunto de los permisos del perfil,
            // en lugar de crear una tabla separada, podemos usar un enfoque diferente:
            // Crear registros específicos para este usuario que "activen" los permisos del perfil

            $userPermissionData = [
                'usu_id' => $id,
                'men_id' => $menId,
                'sub_id' => $subId,
                'opc_id' => $opcId
            ];

            // Verificar si ya existe este permiso específico para el usuario
            $existingUserPermission = DB::table('tbl_usu_perm')
                ->where($userPermissionData)
                ->first();

            if ($grant && !$existingUserPermission) {
                // Otorgar permiso específico al usuario
                DB::table('tbl_usu_perm')->insert($userPermissionData);
                $processedCount++;
            } elseif (!$grant && $existingUserPermission) {
                // Revocar permiso específico del usuario
                DB::table('tbl_usu_perm')
                    ->where($userPermissionData)
                    ->delete();
                $processedCount++;
            }
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => "Se procesaron {$processedCount} cambios de permisos correctamente"
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error al asignar permisos: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Obtener permisos activos de un usuario (combinando perfil + individuales)
 */
public function getActivePermissions($id)
{
    try {
        $usuario = Usuario::find($id);
        
        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Obtener permisos disponibles del perfil
        $permisosDisponibles = DB::table('tbl_perm')
            ->join('tbl_men', 'tbl_perm.men_id', '=', 'tbl_men.men_id')
            ->leftJoin('tbl_sub', 'tbl_perm.sub_id', '=', 'tbl_sub.sub_id')
            ->leftJoin('tbl_opc', 'tbl_perm.opc_id', '=', 'tbl_opc.opc_id')
            ->where('tbl_perm.per_id', $usuario->per_id)
            ->where('tbl_men.men_est', true)
            ->select(
                'tbl_perm.men_id',
                'tbl_perm.sub_id', 
                'tbl_perm.opc_id',
                'tbl_men.men_nom',
                'tbl_sub.sub_nom',
                'tbl_opc.opc_nom'
            )
            ->get();

        // Obtener permisos específicamente asignados al usuario
        $permisosUsuario = DB::table('tbl_usu_perm')
            ->where('usu_id', $id)
            ->get();

        // Crear conjunto de permisos activos del usuario
        $permisosActivos = [];
        
        foreach ($permisosDisponibles as $permisoDisponible) {
            // Verificar si el usuario tiene este permiso específico activado
            $tienePermiso = $permisosUsuario->contains(function ($permisoUser) use ($permisoDisponible) {
                return $permisoUser->men_id == $permisoDisponible->men_id &&
                       $permisoUser->sub_id == $permisoDisponible->sub_id &&
                       $permisoUser->opc_id == $permisoDisponible->opc_id;
            });

            if ($tienePermiso) {
                $permisosActivos[] = [
                    'men_id' => $permisoDisponible->men_id,
                    'sub_id' => $permisoDisponible->sub_id,
                    'opc_id' => $permisoDisponible->opc_id,
                    'men_nom' => $permisoDisponible->men_nom,
                    'sub_nom' => $permisoDisponible->sub_nom,
                    'opc_nom' => $permisoDisponible->opc_nom
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'usuario_id' => $id,
                'permisos_activos' => $permisosActivos,
                'total_disponibles' => $permisosDisponibles->count(),
                'total_activos' => count($permisosActivos)
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener permisos activos: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Copiar permisos de un usuario a otro (del mismo perfil)
 */
public function copyUserPermissions(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'target_user_id' => 'required|integer|exists:tbl_usu,usu_id|different:' . $id,
        'overwrite' => 'boolean'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Datos de validación incorrectos',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $usuarioOrigen = Usuario::find($id);
        $usuarioDestino = Usuario::find($request->target_user_id);
        
        if (!$usuarioOrigen || !$usuarioDestino) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Verificar que ambos usuarios tengan el mismo perfil
        if ($usuarioOrigen->per_id !== $usuarioDestino->per_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden copiar permisos entre usuarios del mismo perfil'
            ], 400);
        }

        DB::beginTransaction();

        $overwrite = $request->overwrite ?? false;

        // Si se especifica sobrescribir, eliminar permisos existentes del usuario destino
        if ($overwrite) {
            DB::table('tbl_usu_perm')
                ->where('usu_id', $request->target_user_id)
                ->delete();
        }

        // Obtener permisos del usuario origen
        $permisosOrigen = DB::table('tbl_usu_perm')
            ->where('usu_id', $id)
            ->get();

        $copiedCount = 0;

        // Copiar cada permiso
        foreach ($permisosOrigen as $permiso) {
            $newPermission = [
                'usu_id' => $request->target_user_id,
                'men_id' => $permiso->men_id,
                'sub_id' => $permiso->sub_id,
                'opc_id' => $permiso->opc_id
            ];

            // Verificar si ya existe (solo si no se sobrescribe)
            if (!$overwrite) {
                $exists = DB::table('tbl_usu_perm')
                    ->where($newPermission)
                    ->exists();
                
                if ($exists) {
                    continue;
                }
            }

            DB::table('tbl_usu_perm')->insert($newPermission);
            $copiedCount++;
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => "Se copiaron {$copiedCount} permisos correctamente"
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error al copiar permisos: ' . $e->getMessage()
        ], 500);
    }
}
}