<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OptionController extends Controller
{
    /**
     * Listar todas las opciones
     */
    public function index()
    {
        try {
            $opciones = DB::table('tbl_opc')
                ->join('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_opc.opc_id', 
                    'tbl_opc.opc_nom', 
                    'tbl_opc.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_opc.opc_componente',
                    'tbl_opc.opc_eje',
                    'tbl_opc.opc_ventana_directa',
                    'tbl_opc.opc_est'
                )
                ->orderBy('tbl_opc.opc_id')
                ->get();

            // Para cada opción, obtener a qué submenús está asociada
            foreach ($opciones as $opcion) {
                $submenus = DB::table('tbl_sub')
                    ->join('tbl_sub_opc', 'tbl_sub.sub_id', '=', 'tbl_sub_opc.sub_id')
                    ->select('tbl_sub.sub_id', 'tbl_sub.sub_nom')
                    ->where('tbl_sub_opc.opc_id', $opcion->opc_id)
                    ->get();
                
                $opcion->submenus = $submenus;
            }

            return response()->json([
                'status' => 'success',
                'opciones' => $opciones
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener opciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear una nueva opción y asignar a submenú(s)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'opc_nom' => 'required|string|max:100',
            'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
            'opc_componente' => 'nullable|string|max:100',
            'opc_eje' => 'integer|min:1|max:9',
            'opc_ventana_directa' => 'boolean',
            'submenu_ids' => 'required|array',
            'submenu_ids.*' => 'integer|exists:tbl_sub,sub_id'
        ]);

        try {
            // Iniciar transacción
            DB::beginTransaction();

            // Valores por defecto
            $opcionData = [
                'opc_nom' => $validated['opc_nom'],
                'ico_id' => $validated['ico_id'] ?? null,
                'opc_componente' => $validated['opc_componente'] ?? null,
                'opc_ventana_directa' => $validated['opc_ventana_directa'] ?? false, // ✅ AGREGAR ESTA LÍNEA
                'opc_est' => true
            ];

            // Insertar opción
            $opcionId = DB::table('tbl_opc')->insertGetId($opcionData, 'opc_id');

            // Asociar con los submenús seleccionados
            foreach ($validated['submenu_ids'] as $submenuId) {
                DB::table('tbl_sub_opc')->insert([
                    'sub_id' => $submenuId,
                    'opc_id' => $opcionId
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Opción creada y asignada correctamente',
                'opcion_id' => $opcionId
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear la opción: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar una opción específica
     */
    /**
     * Mostrar una opción específica
     */
    public function show($id)
    {
        try {
            $opcion = DB::table('tbl_opc')
                ->join('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_opc.opc_id', 
                    'tbl_opc.opc_nom', 
                    'tbl_opc.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_opc.opc_componente',
                    'tbl_opc.opc_eje',
                    'tbl_opc.opc_ventana_directa',
                    'tbl_opc.opc_est'
                )
                ->where('tbl_opc.opc_id', $id)
                ->first();

            if (!$opcion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Opción no encontrada'
                ], 404);
            }

            // Obtener submenús asociados
            $submenus = DB::table('tbl_sub')
                ->join('tbl_sub_opc', 'tbl_sub.sub_id', '=', 'tbl_sub_opc.sub_id')
                ->select(
                    'tbl_sub.sub_id', 
                    'tbl_sub.sub_nom'
                )
                ->where('tbl_sub_opc.opc_id', $id)
                ->get();
            
            $opcion->submenus = $submenus;

            return response()->json([
                'status' => 'success',
                'opcion' => $opcion
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener la opción: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una opción y sus asignaciones a submenús
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'opc_nom' => 'required|string|max:100',
            'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
            'opc_componente' => 'nullable|string|max:100',
            'opc_eje' => 'integer|min:1|max:9',
            'opc_ventana_directa' => 'boolean',
            'submenu_ids' => 'array',
            'submenu_ids.*' => 'integer|exists:tbl_sub,sub_id'
        ]);

        try {
            DB::beginTransaction();

            $opcion = DB::table('tbl_opc')->where('opc_id', $id)->first();
            
            if (!$opcion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Opción no encontrada'
                ], 404);
            }

            // Actualizar datos de la opción
            $opcionData = [
                'opc_nom' => $validated['opc_nom'],
                'ico_id' => $validated['ico_id'] ?? null,
                'opc_componente' => $validated['opc_componente'] ?? null,
                'opc_eje' => $validated['opc_eje'] ?? $opcion->opc_eje,
                'opc_ventana_directa' => $validated['opc_ventana_directa'] ?? $opcion->opc_ventana_directa
            ];

            DB::table('tbl_opc')
                ->where('opc_id', $id)
                ->update($opcionData);

            // Si se proporcionaron nuevos submenús, actualizar las asignaciones
            if (isset($validated['submenu_ids'])) {
                // Eliminar asignaciones actuales
                DB::table('tbl_sub_opc')
                    ->where('opc_id', $id)
                    ->delete();
                
                // Crear nuevas asignaciones
                foreach ($validated['submenu_ids'] as $submenuId) {
                    DB::table('tbl_sub_opc')->insert([
                        'sub_id' => $submenuId,
                        'opc_id' => $id
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Opción actualizada correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar la opción: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar el estado de una opción (activar/desactivar)
     */
    public function toggleStatus($id)
    {
        try {
            $opcion = DB::table('tbl_opc')->where('opc_id', $id)->first();
            
            if (!$opcion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Opción no encontrada'
                ], 404);
            }

            // Cambiar el estado actual
            $newStatus = !$opcion->opc_est;
            
            DB::table('tbl_opc')
                ->where('opc_id', $id)
                ->update(['opc_est' => $newStatus]);

            $statusText = $newStatus ? 'activada' : 'desactivada';

            return response()->json([
                'status' => 'success',
                'message' => "Opción {$statusText} correctamente",
                'new_status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar el estado de la opción: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una opción
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            
            $opcion = DB::table('tbl_opc')->where('opc_id', $id)->first();
            
            if (!$opcion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Opción no encontrada'
                ], 404);
            }

            // Eliminar relaciones en la tabla de submenú-opción
            DB::table('tbl_sub_opc')
                ->where('opc_id', $id)
                ->delete();

            // Eliminar permisos asociados a la opción
            DB::table('tbl_perm')
                ->where('opc_id', $id)
                ->delete();

            // Eliminar la opción
            DB::table('tbl_opc')
                ->where('opc_id', $id)
                ->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Opción eliminada correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar la opción: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener todas las opciones por submenú
     */
    public function getBySubmenu($submenuId)
    {
        try {
            $submenu = DB::table('tbl_sub')->where('sub_id', $submenuId)->first();
            
            if (!$submenu) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Submenú no encontrado'
                ], 404);
            }

            $opciones = DB::table('tbl_opc')
                ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
                ->join('tbl_ico', 'tbl_opc.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_opc.opc_id', 
                    'tbl_opc.opc_nom', 
                    'tbl_opc.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_opc.opc_componente',
                    'tbl_opc.opc_eje',
                    'tbl_opc.opc_est'
                )
                ->where('tbl_sub_opc.sub_id', $submenuId)
                ->orderBy('tbl_opc.opc_nom')
                ->get();

            return response()->json([
                'status' => 'success',
                'submenu_nombre' => $submenu->sub_nom,
                'opciones' => $opciones
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener opciones: ' . $e->getMessage()
            ], 500);
        }
    }
}