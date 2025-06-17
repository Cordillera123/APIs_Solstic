<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Login de usuario y creación de token
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos incorrectos',
                'errors' => $validator->errors()
            ], 422);
        }

        $usuario = Usuario::where('usu_cor', $request->email)->first();
        
        if (!$usuario) {
            return response()->json([
                'message' => 'Credenciales inválidas'
            ], 401);
        }
        
        // Verificar que el usuario esté activo
        if ($usuario->est_id != 1) { // Asumiendo que 1 es el estado 'activo'
            return response()->json([
                'message' => 'Usuario inactivo o suspendido'
            ], 403);
        }
        
        // Verificar contraseña - Compatible con texto plano
        $passwordValid = false;
        
        // Primero intentar verificación directa (texto plano)
        if ($request->password === $usuario->usu_con) {
            $passwordValid = true;
            
            // Opcional: actualizar la contraseña a Bcrypt para futuros logins
            // Quitar el comentario si deseas migrar gradualmente a Bcrypt


            // $usuario->usu_con = Hash::make($request->password);
            // $usuario->save();
        } else {
            // Si no coincide con texto plano, intentar con Bcrypt
            // (por si alguna contraseña ya está hasheada)
            try {
                $passwordValid = Hash::check($request->password, $usuario->usu_con);
            } catch (\Exception $e) {
                // Ignorar errores de Bcrypt
                $passwordValid = false;
            }
        }
        
        if (!$passwordValid) {
            return response()->json([
                'message' => 'Credenciales inválidas'
            ], 401);
        }
        
        // Login exitoso
        // Crear token
        $token = $usuario->createToken('auth_token')->plainTextToken;
        
        // Obtener información del usuario
        $userInfo = [
            'id' => $usuario->usu_id,
            'nombre' => trim("{$usuario->usu_nom} {$usuario->usu_nom2} {$usuario->usu_ape} {$usuario->usu_ape2}"),
            'email' => $usuario->usu_cor,
            'cedula' => $usuario->usu_ced,
            'perfil' => $usuario->perfil ? $usuario->perfil->per_nom : null,
            'estado' => $usuario->estado ? $usuario->estado->est_nom : null,
        ];
        
        // Obtener permisos del usuario con iconos usando el nuevo método
        $permisos = $this->getUserMenus($usuario->usu_id);
        
        return response()->json([
            'message' => 'Login exitoso',
            'user' => $userInfo,
            'permisos' => $permisos,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
    
    /**
     * Logout de usuario (Revocar token)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        
        return response()->json([
            'message' => 'Sesión cerrada exitosamente'
        ]);
    }
    
    /**
     * Obtener información del usuario autenticado
     */
    public function user(Request $request)
    {
        $user = $request->user();
        $userInfo = DB::table('tbl_usu')
            ->join('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
            ->select(
                'tbl_usu.usu_id as id',
                'tbl_usu.usu_cor as email',
                DB::raw("CONCAT(tbl_usu.usu_nom, ' ', tbl_usu.usu_ape) as nombre"),
                'tbl_per.per_nom as perfil'
            )
            ->where('tbl_usu.usu_id', $user->usu_id)
            ->first();
        
        // Obtener permisos del usuario (menús, submenús y opciones)
        $permisos = $this->getUserMenus($user->usu_id);
        
        return response()->json([
            'status' => 'success',
            'user' => $userInfo,
            'permisos' => $permisos
        ]);
    }

    /**
     * Obtener menús y permisos del usuario incluyendo iconos
     */
    /**
 * Obtener menús y permisos del usuario incluyendo iconos
 */
/**
 * Obtener menús y permisos del usuario incluyendo iconos
 */
/**
 * Obtener menús y permisos del usuario incluyendo iconos
 */
/**
 * Obtener menús y permisos del usuario incluyendo iconos
 */
public function getUserMenus($userId)
{
    error_log('DEBUG 1 - Iniciando getUserMenus para usuario: ' . $userId);
    
    // Obtener el perfil del usuario
    $usuario = DB::table('tbl_usu')
        ->join('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
        ->select('tbl_per.per_id', 'tbl_usu.usu_id', 'tbl_per.per_nom', 'tbl_usu.usu_nom', 'tbl_usu.usu_ape')
        ->where('tbl_usu.usu_id', $userId)
        ->first();
    
    error_log('DEBUG 1 - Usuario encontrado: ' . json_encode($usuario));
    
    if (!$usuario) {
        error_log('ERROR - Usuario no encontrado con ID: ' . $userId);
        return [];
    }
    
    // Verificar si el usuario tiene permisos individuales asignados
    $permisosIndividuales = DB::table('tbl_usu_perm')
        ->where('usu_id', $userId)
        ->get();
    
    error_log('DEBUG 2 - Permisos individuales count: ' . $permisosIndividuales->count());
    
    $tienePermisosIndividuales = $permisosIndividuales->count() > 0;
    
    if ($tienePermisosIndividuales) {
        // CASO 1: Usuario tiene permisos individuales - mostrar SOLO esos
        error_log('DEBUG 3 - Usuario TIENE permisos individuales, usando lógica INDIVIDUAL');
        $resultado = $this->getUserIndividualMenus($userId, $usuario->per_id);
        error_log('DEBUG 4 - Resultado individual count: ' . count($resultado));
        return $resultado;
    } else {
        // CASO 2: Usuario NO tiene permisos individuales 
        // NUEVA LÓGICA: En lugar de mostrar todos del perfil, mostrar array vacío
        // Esto fuerza a que primero se asignen permisos individuales
        error_log('DEBUG 5 - Usuario NO tiene permisos individuales, retornando VACÍO');
        
        // OPCIÓN A: Retornar vacío (recomendado para tu caso)
        return [];
        
        // OPCIÓN B: Si quieres que muestre todos los del perfil cuando no tiene individuales
        // descomenta la siguiente línea y comenta el return [] de arriba:
        // return $this->getProfileMenus($usuario->per_id);
    }
}
public function debugUserPermissions($userId)
{
    try {
        // Información del usuario
        $usuario = DB::table('tbl_usu')
            ->join('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
            ->select('tbl_per.per_id', 'tbl_usu.usu_id', 'tbl_per.per_nom', 'tbl_usu.usu_nom', 'tbl_usu.usu_ape')
            ->where('tbl_usu.usu_id', $userId)
            ->first();

        // Permisos individuales
        $permisosIndividuales = DB::table('tbl_usu_perm')
            ->where('usu_id', $userId)
            ->get();

        // Permisos del perfil
        $permisosPerfil = DB::table('tbl_perm')
            ->join('tbl_men', 'tbl_perm.men_id', '=', 'tbl_men.men_id')
            ->leftJoin('tbl_sub', 'tbl_perm.sub_id', '=', 'tbl_sub.sub_id')
            ->leftJoin('tbl_opc', 'tbl_perm.opc_id', '=', 'tbl_opc.opc_id')
            ->select(
                'tbl_perm.*',
                'tbl_men.men_nom',
                'tbl_sub.sub_nom',
                'tbl_opc.opc_nom'
            )
            ->where('tbl_perm.per_id', $usuario->per_id ?? 0)
            ->get();

        // Resultado final que devuelve getUserMenus
        $menusFinales = $this->getUserMenus($userId);

        return response()->json([
            'status' => 'debug',
            'usuario' => $usuario,
            'permisos_individuales' => [
                'count' => $permisosIndividuales->count(),
                'data' => $permisosIndividuales
            ],
            'permisos_perfil' => [
                'count' => $permisosPerfil->count(),
                'data' => $permisosPerfil
            ],
            'menus_finales' => [
                'count' => count($menusFinales),
                'data' => $menusFinales
            ],
            'logica_usada' => $permisosIndividuales->count() > 0 ? 'individual' : 'perfil',
            'problema' => $permisosIndividuales->count() > 0 && count($menusFinales) > 1 ? 'ERROR: Debería mostrar solo permisos individuales' : 'OK'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}
private function getUserIndividualMenus($userId, $perfilId)
{
    error_log('INDIVIDUAL DEBUG - Iniciando con userId: ' . $userId);
    
    // Obtener SOLO los permisos específicos del usuario
    $permisosUsuario = DB::table('tbl_usu_perm')
        ->where('usu_id', $userId)
        ->get();
    
    error_log('INDIVIDUAL DEBUG - Permisos usuario count: ' . $permisosUsuario->count());
    error_log('INDIVIDUAL DEBUG - Permisos usuario data: ' . json_encode($permisosUsuario->toArray()));
    
    if ($permisosUsuario->isEmpty()) {
        error_log('INDIVIDUAL DEBUG - No hay permisos individuales, retornando vacío');
        return [];
    }

    // Crear mapa de permisos para búsqueda rápida
    $permisosMap = [];
    foreach ($permisosUsuario as $permiso) {
        $key = $permiso->men_id . '-' . ($permiso->sub_id ?: 'null') . '-' . ($permiso->opc_id ?: 'null');
        $permisosMap[$key] = true;
    }
    
    error_log('INDIVIDUAL DEBUG - Mapa de permisos: ' . json_encode(array_keys($permisosMap)));

    // Obtener menús únicos de los permisos del usuario
    $menusIds = $permisosUsuario->pluck('men_id')->unique()->values();
    error_log('INDIVIDUAL DEBUG - IDs de menús a procesar: ' . json_encode($menusIds->toArray()));
    
    // Obtener información de los menús CON COMPONENTES
    $menus = DB::table('tbl_men')
        ->leftJoin('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id')
        ->select(
            'tbl_men.men_id as id',
            'tbl_men.men_nom as nombre',
            'tbl_men.men_componente as componente',
            'tbl_men.men_ventana_directa as ventana_directa',
            'tbl_men.men_url as url',
            'tbl_ico.ico_nom as icon_nombre',
            'tbl_ico.ico_lib as icon_libreria'
        )
        ->whereIn('tbl_men.men_id', $menusIds)
        ->where('tbl_men.men_est', true)
        ->orderBy('tbl_men.men_orden')
        ->get();

    error_log('INDIVIDUAL DEBUG - Menús encontrados: ' . $menus->count());

    $menusPermitidos = [];

    foreach ($menus as $menu) {
        error_log('INDIVIDUAL DEBUG - Procesando menú: ' . $menu->id . ' - ' . $menu->nombre . ' - Componente: ' . $menu->componente);
        
        $menuKey = $menu->id . '-null-null';
        
        // Solo incluir menús que el usuario tiene asignados individualmente
        if (isset($permisosMap[$menuKey])) {
            error_log('INDIVIDUAL DEBUG - Menú ' . $menu->id . ' tiene permiso directo');
            
            // Obtener submenús que el usuario tiene asignados individualmente para este menú
            $submenusIdsUsuario = $permisosUsuario
                ->where('men_id', $menu->id)
                ->whereNotNull('sub_id')
                ->pluck('sub_id')
                ->unique()
                ->values();
            
            error_log('INDIVIDUAL DEBUG - Submenús IDs para menú ' . $menu->id . ': ' . json_encode($submenusIdsUsuario->toArray()));
            
            $submenusPermitidos = [];
            
            if ($submenusIdsUsuario->isNotEmpty()) {
                // Obtener información de los submenús CON COMPONENTES
                $submenus = DB::table('tbl_sub')
                    ->leftJoin('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id')
                    ->select(
                        'tbl_sub.sub_id as id',
                        'tbl_sub.sub_nom as nombre',
                        'tbl_sub.sub_componente as componente',
                        'tbl_sub.sub_ventana_directa as ventana_directa',
                        'tbl_sub.sub_url as url',
                        'tbl_ico.ico_nom as icon_nombre',
                        'tbl_ico.ico_lib as icon_libreria'
                    )
                    ->whereIn('tbl_sub.sub_id', $submenusIdsUsuario)
                    ->where('tbl_sub.sub_est', true)
                    ->orderBy('tbl_sub.sub_orden')
                    ->get();

                foreach ($submenus as $submenu) {
                    $submenuKey = $menu->id . '-' . $submenu->id . '-null';
                    
                    // Solo incluir submenús que el usuario tiene asignados individualmente
                    if (isset($permisosMap[$submenuKey])) {
                        error_log('INDIVIDUAL DEBUG - Submenú ' . $submenu->id . ' tiene permiso - Componente: ' . $submenu->componente);
                        
                        // Obtener opciones que el usuario tiene asignadas individualmente para este submenú
                        $opcionesIdsUsuario = $permisosUsuario
                            ->where('men_id', $menu->id)
                            ->where('sub_id', $submenu->id)
                            ->whereNotNull('opc_id')
                            ->pluck('opc_id')
                            ->unique()
                            ->values();
                        
                        error_log('INDIVIDUAL DEBUG - Opciones IDs para submenú ' . $submenu->id . ': ' . json_encode($opcionesIdsUsuario->toArray()));
                        
                        $opciones = [];
                        
                        if ($opcionesIdsUsuario->isNotEmpty()) {
                            $opciones = DB::table('tbl_opc')
                                ->leftJoin('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id')
                                ->select(
                                    'tbl_opc.opc_id as id',
                                    'tbl_opc.opc_nom as nombre',
                                    'tbl_opc.opc_componente as componente',
                                    'tbl_opc.opc_ventana_directa as ventana_directa',
                                    'tbl_opc.opc_url as url',
                                    'tbl_ico.ico_nom as icon_nombre',
                                    'tbl_ico.ico_lib as icon_libreria'
                                )
                                ->whereIn('tbl_opc.opc_id', $opcionesIdsUsuario)
                                ->where('tbl_opc.opc_est', true)
                                ->orderBy('tbl_opc.opc_orden')
                                ->get()
                                ->toArray();
                        }
                        
                        $submenu->opciones = $opciones;
                        $submenusPermitidos[] = $submenu;
                    }
                }
            }
            
            $menu->submenus = $submenusPermitidos;
            $menusPermitidos[] = $menu;
            
            error_log('INDIVIDUAL DEBUG - Menú ' . $menu->id . ' agregado con ' . count($submenusPermitidos) . ' submenús');
        } else {
            error_log('INDIVIDUAL DEBUG - Menú ' . $menu->id . ' NO tiene permiso directo, saltando');
        }
    }
    
    error_log('INDIVIDUAL DEBUG - Total menús finales: ' . count($menusPermitidos));
    error_log('INDIVIDUAL DEBUG - Menús finales: ' . json_encode(array_map(function($m) { 
        return [
            'id' => $m->id, 
            'nombre' => $m->nombre, 
            'componente' => $m->componente,
            'ventana_directa' => $m->ventana_directa
        ]; 
    }, $menusPermitidos)));

    return $menusPermitidos;
}

private function getProfileMenus($perfilId)
{
    // Obtener menús permitidos para el perfil CON COMPONENTES
    $menus = DB::table('tbl_men')
        ->join('tbl_perm', 'tbl_men.men_id', '=', 'tbl_perm.men_id')
        ->leftJoin('tbl_ico', 'tbl_men.ico_id', '=', 'tbl_ico.ico_id')
        ->select(
            'tbl_men.men_id as id',
            'tbl_men.men_nom as nombre',
            'tbl_men.men_componente as componente',
            'tbl_men.men_ventana_directa as ventana_directa',
            'tbl_men.men_url as url',
            'tbl_ico.ico_nom as icon_nombre',
            'tbl_ico.ico_lib as icon_libreria'
        )
        ->where('tbl_perm.per_id', $perfilId)
        ->where('tbl_men.men_est', true)
        ->whereNull('tbl_perm.sub_id')
        ->whereNull('tbl_perm.opc_id')
        ->groupBy('tbl_men.men_id', 'tbl_men.men_nom', 'tbl_men.men_componente', 'tbl_men.men_ventana_directa', 'tbl_men.men_url', 'tbl_ico.ico_nom', 'tbl_ico.ico_lib')
        ->orderBy('tbl_men.men_id')
        ->get();
    
    // Para cada menú, obtener sus submenús
    foreach ($menus as $menu) {
        $submenus = DB::table('tbl_sub')
            ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->join('tbl_perm', function ($join) use ($perfilId) {
                $join->on('tbl_sub.sub_id', '=', 'tbl_perm.sub_id')
                     ->where('tbl_perm.per_id', '=', $perfilId);
            })
            ->leftJoin('tbl_ico', 'tbl_sub.ico_id', '=', 'tbl_ico.ico_id')
            ->select(
                'tbl_sub.sub_id as id',
                'tbl_sub.sub_nom as nombre',
                'tbl_sub.sub_componente as componente',
                'tbl_sub.sub_ventana_directa as ventana_directa',
                'tbl_sub.sub_url as url',
                'tbl_ico.ico_nom as icon_nombre',
                'tbl_ico.ico_lib as icon_libreria'
            )
            ->where('tbl_men_sub.men_id', $menu->id)
            ->where('tbl_sub.sub_est', true)
            ->whereNull('tbl_perm.opc_id')
            ->groupBy('tbl_sub.sub_id', 'tbl_sub.sub_nom', 'tbl_sub.sub_componente', 'tbl_sub.sub_ventana_directa', 'tbl_sub.sub_url', 'tbl_ico.ico_nom', 'tbl_ico.ico_lib')
            ->orderBy('tbl_sub.sub_id')
            ->get();
        
        // Para cada submenú, obtener sus opciones
        foreach ($submenus as $submenu) {
            $opciones = DB::table('tbl_opc')
                ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
                ->join('tbl_perm', function ($join) use ($perfilId, $menu, $submenu) {
                    $join->on('tbl_opc.opc_id', '=', 'tbl_perm.opc_id')
                         ->where('tbl_perm.per_id', '=', $perfilId)
                         ->where('tbl_perm.men_id', '=', $menu->id)
                         ->where('tbl_perm.sub_id', '=', $submenu->id);
                })
                ->leftJoin('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id')
                ->select(
                    'tbl_opc.opc_id as id',
                    'tbl_opc.opc_nom as nombre',
                    'tbl_opc.opc_componente as componente',
                    'tbl_opc.opc_ventana_directa as ventana_directa',
                    'tbl_opc.opc_url as url',
                    'tbl_ico.ico_nom as icon_nombre',
                    'tbl_ico.ico_lib as icon_libreria'
                )
                ->where('tbl_sub_opc.sub_id', $submenu->id)
                ->where('tbl_opc.opc_est', true)
                ->groupBy('tbl_opc.opc_id', 'tbl_opc.opc_nom', 'tbl_opc.opc_componente', 'tbl_opc.opc_ventana_directa', 'tbl_opc.opc_url', 'tbl_ico.ico_nom', 'tbl_ico.ico_lib')
                ->orderBy('tbl_opc.opc_id')
                ->get();
            
            $submenu->opciones = $opciones;
        }
        
        $menu->submenus = $submenus;
    }
    
    return $menus;
}

public function userHasSpecificPermission($userId, $menuId, $submenuId = null, $opcionId = null)
{
    // Verificar que el usuario exista y obtenr su perfil
    $usuario = DB::table('tbl_usu')->where('usu_id', $userId)->first();
    if (!$usuario) {
        return false;
    }
    
    // Verificar que el permiso esté disponible en el perfil
    $perfilHasPermission = DB::table('tbl_perm')
        ->where('per_id', $usuario->per_id)
        ->where('men_id', $menuId)
        ->where('sub_id', $submenuId)
        ->where('opc_id', $opcionId)
        ->exists();
    
    if (!$perfilHasPermission) {
        return false;
    }
    
    // Verificar que el usuario tenga el permiso específico asignado
    return DB::table('tbl_usu_perm')
        ->where('usu_id', $userId)
        ->where('men_id', $menuId)
        ->where('sub_id', $submenuId)
        ->where('opc_id', $opcionId)
        ->exists();
}
    
    /**
     * Obtener permisos de un perfil
     * Nota: Este método está obsoleto y será reemplazado por getUserMenus()
     */
    private function getPermisos($perfilId)
    {
        // Usar el nuevo método getUserMenus para mantener consistencia
        $usuario = DB::table('tbl_usu')
            ->where('per_id', $perfilId)
            ->first();
            
        if ($usuario) {
            return $this->getUserMenus($usuario->usu_id);
        }
        
        // Fallback al método antiguo si no se encuentra un usuario con ese perfil
        $permisos = DB::table('tbl_perm')
            ->join('tbl_men', 'tbl_perm.men_id', '=', 'tbl_men.men_id')
            ->leftJoin('tbl_sub', 'tbl_perm.sub_id', '=', 'tbl_sub.sub_id')
            ->leftJoin('tbl_opc', 'tbl_perm.opc_id', '=', 'tbl_opc.opc_id')
            ->leftJoin('tbl_ico as ico_men', 'tbl_men.ico_id', '=', 'ico_men.ico_id')
            ->leftJoin('tbl_ico as ico_sub', 'tbl_sub.ico_id', '=', 'ico_sub.ico_id')
            ->leftJoin('tbl_ico as ico_opc', 'tbl_opc.ico_id', '=', 'ico_opc.ico_id')
            ->where('tbl_perm.per_id', $perfilId)
            ->where('tbl_men.men_eje', 1) // Solo menús habilitados
            ->select(
                'tbl_men.men_id', 'tbl_men.men_nom',
                'ico_men.ico_nom as men_icon_nombre', 'ico_men.ico_lib as men_icon_libreria',
                'tbl_sub.sub_id', 'tbl_sub.sub_nom', 'tbl_sub.sub_eje',
                'ico_sub.ico_nom as sub_icon_nombre', 'ico_sub.ico_lib as sub_icon_libreria',
                'tbl_opc.opc_id', 'tbl_opc.opc_nom', 'tbl_opc.opc_eje',
                'ico_opc.ico_nom as opc_icon_nombre', 'ico_opc.ico_lib as opc_icon_libreria'
            )
            ->get();
        
        // Estructura organizada por menús -> submenús -> opciones
        $menuTree = [];
        
        foreach ($permisos as $item) {
            // Solo procesar si el menú no existe o si existe pero con datos diferentes
            if (!isset($menuTree[$item->men_id])) {
                $menuTree[$item->men_id] = [
                    'id' => $item->men_id,
                    'nombre' => $item->men_nom,
                    'icon_nombre' => $item->men_icon_nombre,
                    'icon_libreria' => $item->men_icon_libreria,
                    'submenus' => []
                ];
            }
            
            // Solo agregar submenú si existe y está habilitado
            if ($item->sub_id && $item->sub_eje == 1) {
                // Verificar si el submenú ya existe
                $submenuExists = false;
                foreach ($menuTree[$item->men_id]['submenus'] as $submenu) {
                    if ($submenu['id'] == $item->sub_id) {
                        $submenuExists = true;
                        break;
                    }
                }
                
                // Si no existe, agregarlo
                if (!$submenuExists) {
                    $submenu = [
                        'id' => $item->sub_id,
                        'nombre' => $item->sub_nom,
                        'icon_nombre' => $item->sub_icon_nombre,
                        'icon_libreria' => $item->sub_icon_libreria,
                        'opciones' => []
                    ];
                    
                    // Agregar opciones si existen y están habilitadas
                    if ($item->opc_id && $item->opc_eje == 1) {
                        $submenu['opciones'][] = [
                            'id' => $item->opc_id,
                            'nombre' => $item->opc_nom,
                            'icon_nombre' => $item->opc_icon_nombre,
                            'icon_libreria' => $item->opc_icon_libreria
                        ];
                    }
                    
                    $menuTree[$item->men_id]['submenus'][] = $submenu;
                }
                // Si existe, agregar opciones
                else {
                    // Encontrar el submenú y agregar la opción
                    foreach ($menuTree[$item->men_id]['submenus'] as &$submenu) {
                        if ($submenu['id'] == $item->sub_id && $item->opc_id && $item->opc_eje == 1) {
                            // Verificar si la opción ya existe
                            $opcionExists = false;
                            foreach ($submenu['opciones'] as $opcion) {
                                if ($opcion['id'] == $item->opc_id) {
                                    $opcionExists = true;
                                    break;
                                }
                            }
                            
                            if (!$opcionExists) {
                                $submenu['opciones'][] = [
                                    'id' => $item->opc_id,
                                    'nombre' => $item->opc_nom,
                                    'icon_nombre' => $item->opc_icon_nombre,
                                    'icon_libreria' => $item->opc_icon_libreria
                                ];
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        // Convertir a array indexado
        return array_values($menuTree);
    }
}