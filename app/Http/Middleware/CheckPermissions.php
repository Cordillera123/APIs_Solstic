<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckPermissions
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        // Verificar que el usuario esté autenticado
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autenticado'
            ], 401);
        }

        $user = Auth::user();
        
        // Verificar que el usuario tenga un perfil asignado
        if (!$user->per_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario sin perfil asignado'
            ], 403);
        }

        // Verificar que el usuario esté activo
        if ($user->est_id != 1) { // Asumiendo que 1 es el estado activo
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario inactivo'
            ], 403);
        }

        // Si no se especifican permisos, solo verificar autenticación
        if (empty($permissions)) {
            return $next($request);
        }

        // Verificar permisos específicos
        $hasPermission = $this->checkUserPermissions($user->per_id, $permissions);
        
        if (!$hasPermission) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes permisos para realizar esta acción',
                'required_permissions' => $permissions
            ], 403);
        }

        return $next($request);
    }

    /**
     * Verificar si el usuario tiene los permisos requeridos
     */
    private function checkUserPermissions($perfilId, $permissions)
    {
        foreach ($permissions as $permission) {
            if ($this->hasSpecificPermission($perfilId, $permission)) {
                return true; // Al menos uno de los permisos requeridos
            }
        }
        
        return false;
    }

    /**
     * Verificar un permiso específico (actualizado para permisos individuales)
     * Formato del permiso: "menu.submenu.opcion" o "menu.submenu" o "menu"
     */
    private function hasSpecificPermission($perfilId, $permission)
    {
        $parts = explode('.', $permission);
        
        // Validar formato del permiso
        if (count($parts) < 1 || count($parts) > 3) {
            return false;
        }

        $menuNombre = $parts[0];
        $submenuNombre = $parts[1] ?? null;
        $opcionNombre = $parts[2] ?? null;

        try {
            // Obtener el usuario actual para verificar permisos individuales
            $user = Auth::user();
            if (!$user) {
                return false;
            }

            // Construir la consulta base para verificar disponibilidad en el perfil
            $query = DB::table('tbl_perm')
                ->join('tbl_men', 'tbl_perm.men_id', '=', 'tbl_men.men_id')
                ->where('tbl_perm.per_id', $perfilId)
                ->where('tbl_men.men_nom', $menuNombre)
                ->where('tbl_men.men_est', true);

            // Si se especifica submenú
            if ($submenuNombre) {
                $query->join('tbl_sub', 'tbl_perm.sub_id', '=', 'tbl_sub.sub_id')
                      ->where('tbl_sub.sub_nom', $submenuNombre)
                      ->where('tbl_sub.sub_est', true);
            } else {
                $query->whereNull('tbl_perm.sub_id');
            }

            // Si se especifica opción
            if ($opcionNombre) {
                $query->join('tbl_opc', 'tbl_perm.opc_id', '=', 'tbl_opc.opc_id')
                      ->where('tbl_opc.opc_nom', $opcionNombre)
                      ->where('tbl_opc.opc_est', true);
            } else {
                $query->whereNull('tbl_perm.opc_id');
            }

            // Verificar que el permiso esté disponible en el perfil
            $permisoDisponible = $query->first();
            
            if (!$permisoDisponible) {
                return false;
            }

            // Ahora verificar si el usuario tiene este permiso específico asignado
            $userPermissionQuery = DB::table('tbl_usu_perm')
                ->where('usu_id', $user->usu_id)
                ->where('men_id', $permisoDisponible->men_id);

            if ($submenuNombre) {
                $userPermissionQuery->where('sub_id', $permisoDisponible->sub_id);
            } else {
                $userPermissionQuery->whereNull('sub_id');
            }

            if ($opcionNombre) {
                $userPermissionQuery->where('opc_id', $permisoDisponible->opc_id);
            } else {
                $userPermissionQuery->whereNull('opc_id');
            }

            return $userPermissionQuery->exists();

        } catch (\Exception $e) {
            // Log del error si es necesario
            Log::error('Error verificando permiso: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verificar si el usuario es super administrador
     */
    private function isSuperAdmin($perfilId)
    {
        $perfil = DB::table('tbl_per')
            ->where('per_id', $perfilId)
            ->where('per_nom', 'ILIKE', '%super%admin%')
            ->first();

        return $perfil !== null;
    }

    /**
     * Método estático para verificar permisos desde controladores
     */
    public static function userHasPermission($permission, $userId = null)
    {
        if (!$userId) {
            $user = Auth::user();
            if (!$user) return false;
            $perfilId = $user->per_id;
        } else {
            $user = DB::table('tbl_usu')->where('usu_id', $userId)->first();
            if (!$user) return false;
            $perfilId = $user->per_id;
        }

        $middleware = new self();
        return $middleware->hasSpecificPermission($perfilId, $permission);
    }

    /**
     * Verificar múltiples permisos (AND)
     */
    public static function userHasAllPermissions($permissions, $userId = null)
    {
        foreach ($permissions as $permission) {
            if (!self::userHasPermission($permission, $userId)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Verificar múltiples permisos (OR)
     */
    public static function userHasAnyPermission($permissions, $userId = null)
    {
        foreach ($permissions as $permission) {
            if (self::userHasPermission($permission, $userId)) {
                return true;
            }
        }
        return false;
    }
}