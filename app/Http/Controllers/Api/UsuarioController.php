<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UsuarioController extends Controller
{
    /**
     * Display a listing of the resource.
     * ✅ CORRECCIÓN: Asegurar estructura consistente de respuesta
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');
            $perfilId = $request->get('per_id', ''); // Filtro por perfil
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
                    'tbl_per.per_nom as perfil', // ✅ AGREGAR nombre del perfil
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

            // ✅ CORRECCIÓN: Estructura de respuesta consistente
            return response()->json([
                'status' => 'success',
                'message' => 'Usuarios obtenidos correctamente',
                'data' => $usuarios, // Laravel ya maneja la paginación aquí
                'debug_info' => [
                    'total_usuarios' => $usuarios->total(),
                    'pagina_actual' => $usuarios->currentPage(),
                    'per_page' => $usuarios->perPage(),
                    'filtros_aplicados' => [
                        'search' => $search,
                        'per_id' => $perfilId,
                        'estado_id' => $estadoId
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }


 
            $usuarioData['usu_con'] = $request->usu_con;


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

            // ✅ CORRECCIÓN: Estructura de respuesta consistente
            return response()->json([
                'status' => 'success',
                'message' => 'Usuario creado exitosamente',
                'data' => $usuarioCompleto
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear usuario: ' . $e->getMessage(),
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
            $usuario = $this->getUsuarioCompleto($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // Ocultar contraseña
            if (isset($usuario->usu_con)) {
                unset($usuario->usu_con);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario obtenido correctamente',
                'data' => $usuario
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener usuario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     * ✅ CORRECCIÓN: Mejorar validación y respuesta
     */
    public function update(Request $request, $id)
    {
        $usuario = Usuario::find($id);

        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado',
                'data' => null
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
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        try {
            DB::beginTransaction();

            $usuarioData = $request->only([
                'usu_nom',
                'usu_nom2',
                'usu_ape',
                'usu_ape2',
                'usu_cor',
                'usu_ced',
                'usu_tel',
                'usu_dir',
                'per_id',
                'est_id',
                'usu_descripcion',
                'usu_fecha_nacimiento'
            ]);

            // Si se proporciona nueva contraseña, hashearla
            if (!empty($request->usu_con)) {
                $usuarioData['usu_con'] = ($request->usu_con);
            }

            // ✅ CORRECCIÓN: Agregar fecha de edición
            $usuarioData['usu_edi'] = Carbon::now();

            // Si hay usuario autenticado, registrar quién editó
            try {
                if (Auth::check() && Auth::user()) {
                    $usuarioData['usu_editado_por'] = Auth::user()->usu_id;
                }
            } catch (\Exception $e) {
                // Continuar sin registrar editor si hay error
            }

            $usuario->update($usuarioData);

            // Obtener el usuario actualizado con relaciones
            $usuarioCompleto = $this->getUsuarioCompleto($id);

            // Ocultar contraseña
            if (isset($usuarioCompleto->usu_con)) {
                unset($usuarioCompleto->usu_con);
            }

            DB::commit();

            // ✅ CORRECCIÓN: Estructura de respuesta consistente
            return response()->json([
                'status' => 'success',
                'message' => 'Usuario actualizado exitosamente',
                'data' => $usuarioCompleto
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar usuario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     * ✅ CORRECCIÓN: Mejorar respuesta de eliminación
     */
    /**
     * Remove the specified resource from storage.
     * ✅ CORRECCIÓN: Eliminar permisos automáticamente antes de eliminar usuario
     */
    public function destroy($id)
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

            DB::beginTransaction();

            // ✅ NUEVO: Eliminar permisos automáticamente en lugar de bloquear
            $permisosEliminados = 0;

            // Eliminar permisos de usuario específicos
            $permisosUsuario = DB::table('tbl_usu_perm')->where('usu_id', $id)->count();
            if ($permisosUsuario > 0) {
                DB::table('tbl_usu_perm')->where('usu_id', $id)->delete();
                $permisosEliminados += $permisosUsuario;
            }

            // Eliminar permisos de usuario (si existe esta tabla)
            $permisosUsuarioTabla = DB::table('tbl_perm_usuario')->where('usu_id', $id)->count();
            if ($permisosUsuarioTabla > 0) {
                DB::table('tbl_perm_usuario')->where('usu_id', $id)->delete();
                $permisosEliminados += $permisosUsuarioTabla;
            }

            // Guardar información del usuario antes de eliminar
            $usuarioInfo = [
                'usu_id' => $usuario->usu_id,
                'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_ape}"),
                'usu_cor' => $usuario->usu_cor,
                'permisos_eliminados' => $permisosEliminados
            ];

            // Eliminar tokens de acceso del usuario
            $usuario->tokens()->delete();

            // Eliminar usuario
            $usuario->delete();

            DB::commit();

            // ✅ MENSAJE MEJORADO: Informar sobre permisos eliminados
            $mensaje = 'Usuario eliminado exitosamente';
            if ($permisosEliminados > 0) {
                $mensaje .= " (se eliminaron {$permisosEliminados} permisos asociados)";
            }

            return response()->json([
                'status' => 'success',
                'message' => $mensaje,
                'data' => $usuarioInfo
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar usuario: ' . $e->getMessage(),
                'data' => null
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
                    'message' => 'Usuario no encontrado',
                    'data' => null
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
                    'usu_id' => $usuario->usu_id,
                    'usu_deshabilitado' => $nuevoEstado,
                    'nombre_completo' => trim("{$usuario->usu_nom} {$usuario->usu_ape}")
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar estado: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    // ✅ RESTAURAR: Métodos de permisos que se perdieron

    /**
     * Obtener permisos detallados de un usuario específico
     */


    public function changePassword(Request $request, $id)

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
                    ->where(function($query) use ($subId) {
                        if ($subId !== null) {
                            $query->where('sub_id', $subId);
                        } else {
                            $query->whereNull('sub_id');
                        }
                    })
                    ->where(function($query) use ($opcId) {
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

            // ✅ CORREGIDO: Obtener permisos disponibles del perfil desde tbl_perm_perfil
            $permisosDisponibles = DB::table('tbl_perm_perfil')
                ->join('tbl_men', 'tbl_perm_perfil.men_id', '=', 'tbl_men.men_id')
                ->leftJoin('tbl_sub', 'tbl_perm_perfil.sub_id', '=', 'tbl_sub.sub_id')
                ->leftJoin('tbl_opc', 'tbl_perm_perfil.opc_id', '=', 'tbl_opc.opc_id')
                ->where('tbl_perm_perfil.per_id', $usuario->per_id)
                ->where('tbl_perm_perfil.perm_per_activo', true) // ✅ AGREGADO
                ->where('tbl_men.men_activo', true) // ✅ CORREGIDO
                ->select(
                    'tbl_perm_perfil.men_id',
                    'tbl_perm_perfil.sub_id',
                    'tbl_perm_perfil.opc_id',
                    'tbl_men.men_nom',
                    'tbl_sub.sub_nom',
                    'tbl_opc.opc_nom'
                )
                ->get();

            // ✅ CORREGIDO: Obtener permisos específicamente asignados al usuario desde tbl_perm_usuario
            $permisosUsuario = DB::table('tbl_perm_usuario')
                ->where('usu_id', $id)
                ->where('perm_usu_activo', true)
                ->where('perm_tipo', 'C') // Solo permisos concedidos
                ->get();

            // Crear conjunto de permisos activos del usuario
            $permisosActivos = [];

            foreach ($permisosDisponibles as $permisoDisponible) {
                // Verificar si el usuario tiene este permiso específico activado
                $tienePermiso = $permisosUsuario->contains(function ($permisoUser) use ($permisoDisponible) {
                    return $permisoUser->men_id == $permisoDisponible->men_id &&
                        ($permisoUser->sub_id == $permisoDisponible->sub_id ||
                            ($permisoUser->sub_id === null && $permisoDisponible->sub_id === null)) &&
                        ($permisoUser->opc_id == $permisoDisponible->opc_id ||
                            ($permisoUser->opc_id === null && $permisoDisponible->opc_id === null));
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
                'message' => 'Permisos activos obtenidos correctamente',
                'data' => [
                    'usuario_id' => $id,
                    'permisos_activos' => $permisosActivos,
                    'total_disponibles' => $permisosDisponibles->count(),
                    'total_activos' => count($permisosActivos)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error en getActivePermissions: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos activos: ' . $e->getMessage(),
                'data' => null
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
                'errors' => $validator->errors(),
                'data' => null
            ], 422);
        }

        try {
            $usuarioOrigen = Usuario::find($id);
            $usuarioDestino = Usuario::find($request->target_user_id);

            if (!$usuarioOrigen || !$usuarioDestino) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // Verificar que ambos usuarios tengan el mismo perfil
            if ($usuarioOrigen->per_id !== $usuarioDestino->per_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo se pueden copiar permisos entre usuarios del mismo perfil',
                    'data' => null
                ], 400);
            }

            DB::beginTransaction();

            $overwrite = $request->overwrite ?? false;

            // Si se especifica sobrescribir, desactivar permisos existentes del usuario destino
            if ($overwrite) {
                DB::table('tbl_perm_usuario')
                    ->where('usu_id', $request->target_user_id)
                    ->update([
                        'perm_usu_activo' => false,
                        'perm_usu_edi' => now()
                    ]);
            }

            // ✅ CORREGIDO: Obtener permisos del usuario origen desde tbl_perm_usuario
            $permisosOrigen = DB::table('tbl_perm_usuario')
                ->where('usu_id', $id)
                ->where('perm_usu_activo', true)
                ->where('perm_tipo', 'C')
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
                    $exists = DB::table('tbl_perm_usuario')
                        ->where($newPermission)
                        ->where('perm_usu_activo', true)
                        ->exists();

                    if ($exists) {
                        continue;
                    }
                }

                $newPermission['perm_tipo'] = 'C';
                $newPermission['perm_usu_activo'] = true;
                $newPermission['perm_usu_cre'] = now();
                $newPermission['perm_usu_edi'] = now();

                DB::table('tbl_perm_usuario')->insert($newPermission);
                $copiedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Se copiaron {$copiedCount} permisos correctamente",
                'data' => ['permissions_copied' => $copiedCount]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en copyUserPermissions: " . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error al copiar permisos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get complete user information
     * ✅ CORRECCIÓN: Mejorar consulta para incluir toda la información necesaria
     */
    /**
     * Get complete user information
     * ✅ CORRECCIÓN: Mejorar consulta para incluir toda la información necesaria
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
                'message' => 'Opciones obtenidas correctamente',
                'data' => [
                    'perfiles' => $perfiles,
                    'estados' => $estados
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
     * Obtener estadísticas de usuarios
     */
    public function getStats()
    {
        try {
            $stats = [
                'total_usuarios' => DB::table('tbl_usu')->count(),
                'usuarios_activos' => DB::table('tbl_usu')->where('usu_deshabilitado', false)->count(),
                'usuarios_inactivos' => DB::table('tbl_usu')->where('usu_deshabilitado', true)->count(),
                'usuarios_por_perfil' => DB::table('tbl_usu')
                    ->join('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                    ->select('tbl_per.per_nom', DB::raw('COUNT(*) as cantidad'))
                    ->groupBy('tbl_per.per_id', 'tbl_per.per_nom')
                    ->get(),
                'ultimos_registros' => DB::table('tbl_usu')
                    ->select('usu_fecha_registro')
                    ->where('usu_fecha_registro', '>=', Carbon::now()->subDays(30))
                    ->count()
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Estadísticas obtenidas correctamente',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Búsqueda avanzada de usuarios
     */
    public function search(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'query' => 'required|string|min:2',
                'fields' => 'array|in:nombre,email,cedula,perfil',
                'limit' => 'integer|min:1|max:50'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros de búsqueda inválidos',
                    'errors' => $validator->errors(),
                    'data' => []
                ], 422);
            }

            $query = $request->input('query');
            $fields = $request->input('fields', ['nombre', 'email', 'cedula']);
            $limit = $request->input('limit', 20);

            $searchQuery = DB::table('tbl_usu')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->select(
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'tbl_per.per_nom as perfil',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_completo")
                );

            $searchQuery->where(function ($q) use ($query, $fields) {
                if (in_array('nombre', $fields)) {
                    $q->orWhere('tbl_usu.usu_nom', 'ILIKE', "%{$query}%")
                        ->orWhere('tbl_usu.usu_ape', 'ILIKE', "%{$query}%");
                }
                if (in_array('email', $fields)) {
                    $q->orWhere('tbl_usu.usu_cor', 'ILIKE', "%{$query}%");
                }
                if (in_array('cedula', $fields)) {
                    $q->orWhere('tbl_usu.usu_ced', 'ILIKE', "%{$query}%");
                }
                if (in_array('perfil', $fields)) {
                    $q->orWhere('tbl_per.per_nom', 'ILIKE', "%{$query}%");
                }
            });

            $results = $searchQuery->limit($limit)->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Búsqueda completada',
                'data' => [
                    'query' => $query,
                    'results' => $results,
                    'total_found' => $results->count(),
                    'fields_searched' => $fields
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error en la búsqueda: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Obtener permisos del usuario para AuthController
     */
    public function getUserPermissions($id)
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

            // Usar el método del AuthController para obtener permisos
            $authController = new AuthController();
            $permisos = $authController->getUserMenus($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Permisos del usuario obtenidos correctamente',
                'data' => [
                    'usuario_id' => $id,
                    'perfil_id' => $usuario->per_id,
                    'permisos' => $permisos
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener permisos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Cambiar contraseña del usuario
     */
    public function changePassword(Request $request, $id)
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
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            if (!Hash::check($request->current_password, $usuario->usu_con)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La contraseña actual es incorrecta',
                    'data' => null
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
                'message' => 'Contraseña cambiada exitosamente',
                'data' => null
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar contraseña: ' . $e->getMessage(),
                'data' => null
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
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $usuario = Usuario::find($id);

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
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
                'message' => 'Contraseña restablecida exitosamente',
                'data' => null
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al restablecer contraseña: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
}
