<?php

namespace App\Http\Controllers\Api;

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
    public function showWithButtons($id)
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
                'tbl_opc.opc_est',
                'tbl_opc.opc_tipo_vista',
                'tbl_opc.opc_botones_personalizados'
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

        // *** NUEVA FUNCIONALIDAD: Obtener botones asignados a la opción ***
        $botones = DB::table('tbl_bot')
            ->join('tbl_opc_bot', 'tbl_bot.bot_id', '=', 'tbl_opc_bot.bot_id')
            ->join('tbl_ico', 'tbl_bot.ico_id', '=', 'tbl_ico.ico_id', 'left')
            ->select(
                'tbl_bot.bot_id',
                'tbl_bot.bot_nom',
                'tbl_bot.bot_codigo',
                'tbl_bot.bot_color',
                'tbl_bot.bot_tooltip',
                'tbl_bot.bot_confirmacion',
                'tbl_bot.bot_mensaje_confirmacion',
                'tbl_ico.ico_nom as ico_nombre',
                'tbl_ico.ico_lib as ico_libreria',
                'tbl_opc_bot.opc_bot_requerido',
                'tbl_opc_bot.opc_bot_orden',
                'tbl_opc_bot.opc_bot_activo'
            )
            ->where('tbl_opc_bot.opc_id', $id)
            ->where('tbl_bot.bot_activo', true)
            ->orderBy('tbl_opc_bot.opc_bot_orden')
            ->orderBy('tbl_bot.bot_orden')
            ->get();

        $opcion->botones = $botones;

        return response()->json([
            'status' => 'success',
            'opcion' => $opcion
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener la opción con botones: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Crear una nueva opción CON asignación de botones
 * Versión extendida del método store() existente
 */
public function storeWithButtons(Request $request)
{
    $validated = $request->validate([
        'opc_nom' => 'required|string|max:100',
        'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
        'opc_componente' => 'nullable|string|max:100',
        'opc_eje' => 'integer|min:1|max:9',
        'opc_ventana_directa' => 'boolean',
        'opc_tipo_vista' => 'nullable|string|max:20',
        'opc_botones_personalizados' => 'boolean',
        'submenu_ids' => 'required|array',
        'submenu_ids.*' => 'integer|exists:tbl_sub,sub_id',
        // *** NUEVOS CAMPOS PARA BOTONES ***
        'boton_ids' => 'nullable|array',
        'boton_ids.*' => 'integer|exists:tbl_bot,bot_id'
    ]);

    try {
        DB::beginTransaction();

        // Crear la opción
        $opcionData = [
            'opc_nom' => $validated['opc_nom'],
            'ico_id' => $validated['ico_id'] ?? null,
            'opc_componente' => $validated['opc_componente'] ?? null,
            'opc_eje' => $validated['opc_eje'] ?? 1,
            'opc_ventana_directa' => $validated['opc_ventana_directa'] ?? false,
            'opc_tipo_vista' => $validated['opc_tipo_vista'] ?? 'CRUD',
            'opc_botones_personalizados' => $validated['opc_botones_personalizados'] ?? true,
            'opc_est' => true
        ];

        $opcionId = DB::table('tbl_opc')->insertGetId($opcionData, 'opc_id');

        // Asociar con submenús
        foreach ($validated['submenu_ids'] as $submenuId) {
            DB::table('tbl_sub_opc')->insert([
                'sub_id' => $submenuId,
                'opc_id' => $opcionId
            ]);
        }

        // *** NUEVA FUNCIONALIDAD: Asignar botones si se proporcionaron ***
        if (!empty($validated['boton_ids'])) {
            foreach ($validated['boton_ids'] as $index => $botonId) {
                DB::table('tbl_opc_bot')->insert([
                    'opc_id' => $opcionId,
                    'bot_id' => $botonId,
                    'opc_bot_orden' => $index + 1,
                    'opc_bot_activo' => true
                ]);
            }
        } else {
            // Si no se especificaron botones, asignar botones CRUD básicos por defecto
            $botonesPorDefecto = DB::table('tbl_bot')
                ->whereIn('bot_codigo', ['CREATE', 'READ', 'UPDATE', 'DELETE'])
                ->where('bot_activo', true)
                ->orderBy('bot_orden')
                ->get();

            foreach ($botonesPorDefecto as $index => $boton) {
                DB::table('tbl_opc_bot')->insert([
                    'opc_id' => $opcionId,
                    'bot_id' => $boton->bot_id,
                    'opc_bot_orden' => $index + 1,
                    'opc_bot_activo' => true
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Opción creada con botones asignados correctamente',
            'opcion_id' => $opcionId
        ], 201);
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error al crear la opción con botones: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Actualizar una opción Y sus botones asignados
 * Versión extendida del método update() existente
 */
public function updateWithButtons(Request $request, $id)
{
    $validated = $request->validate([
        'opc_nom' => 'required|string|max:100',
        'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
        'opc_componente' => 'nullable|string|max:100',
        'opc_eje' => 'integer|min:1|max:9',
        'opc_ventana_directa' => 'boolean',
        'opc_tipo_vista' => 'nullable|string|max:20',
        'opc_botones_personalizados' => 'boolean',
        'submenu_ids' => 'array',
        'submenu_ids.*' => 'integer|exists:tbl_sub,sub_id',
        // *** NUEVOS CAMPOS PARA BOTONES ***
        'boton_ids' => 'nullable|array',
        'boton_ids.*' => 'integer|exists:tbl_bot,bot_id'
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
            'opc_ventana_directa' => $validated['opc_ventana_directa'] ?? $opcion->opc_ventana_directa,
            'opc_tipo_vista' => $validated['opc_tipo_vista'] ?? ($opcion->opc_tipo_vista ?? 'CRUD'),
            'opc_botones_personalizados' => $validated['opc_botones_personalizados'] ?? ($opcion->opc_botones_personalizados ?? true)
        ];

        DB::table('tbl_opc')
            ->where('opc_id', $id)
            ->update($opcionData);

        // Actualizar asignaciones de submenús si se proporcionaron
        if (isset($validated['submenu_ids'])) {
            DB::table('tbl_sub_opc')
                ->where('opc_id', $id)
                ->delete();
            
            foreach ($validated['submenu_ids'] as $submenuId) {
                DB::table('tbl_sub_opc')->insert([
                    'sub_id' => $submenuId,
                    'opc_id' => $id
                ]);
            }
        }

        // *** NUEVA FUNCIONALIDAD: Actualizar asignaciones de botones ***
        if (isset($validated['boton_ids'])) {
            // Eliminar asignaciones actuales
            DB::table('tbl_opc_bot')
                ->where('opc_id', $id)
                ->delete();
            
            // Crear nuevas asignaciones
            foreach ($validated['boton_ids'] as $index => $botonId) {
                DB::table('tbl_opc_bot')->insert([
                    'opc_id' => $id,
                    'bot_id' => $botonId,
                    'opc_bot_orden' => $index + 1,
                    'opc_bot_activo' => true
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Opción y botones actualizados correctamente'
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error al actualizar la opción con botones: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Obtener todas las opciones CON sus botones asignados
 * Versión extendida del método index() existente
 */
public function indexWithButtons()
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
                'tbl_opc.opc_est',
                'tbl_opc.opc_tipo_vista',
                'tbl_opc.opc_botones_personalizados'
            )
            ->orderBy('tbl_opc.opc_id')
            ->get();

        foreach ($opciones as $opcion) {
            // Obtener submenús asociados
            $submenus = DB::table('tbl_sub')
                ->join('tbl_sub_opc', 'tbl_sub.sub_id', '=', 'tbl_sub_opc.sub_id')
                ->select('tbl_sub.sub_id', 'tbl_sub.sub_nom')
                ->where('tbl_sub_opc.opc_id', $opcion->opc_id)
                ->get();
            
            $opcion->submenus = $submenus;

            // *** NUEVA FUNCIONALIDAD: Obtener botones asignados ***
            $botones = DB::table('tbl_bot')
                ->join('tbl_opc_bot', 'tbl_bot.bot_id', '=', 'tbl_opc_bot.bot_id')
                ->select(
                    'tbl_bot.bot_id',
                    'tbl_bot.bot_nom',
                    'tbl_bot.bot_codigo',
                    'tbl_bot.bot_color',
                    'tbl_opc_bot.opc_bot_requerido',
                    'tbl_opc_bot.opc_bot_orden'
                )
                ->where('tbl_opc_bot.opc_id', $opcion->opc_id)
                ->where('tbl_opc_bot.opc_bot_activo', true)
                ->where('tbl_bot.bot_activo', true)
                ->orderBy('tbl_opc_bot.opc_bot_orden')
                ->get();

            $opcion->botones = $botones;
            $opcion->total_botones = $botones->count();
        }

        return response()->json([
            'status' => 'success',
            'opciones' => $opciones
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener opciones con botones: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Duplicar una opción CON todos sus botones
 */
public function duplicateWithButtons(Request $request, $id)
{
    $validated = $request->validate([
        'nuevo_nombre' => 'required|string|max:100',
        'copiar_botones' => 'boolean',
        'copiar_submenus' => 'boolean'
    ]);

    try {
        DB::beginTransaction();

        $opcionOriginal = DB::table('tbl_opc')->where('opc_id', $id)->first();
        
        if (!$opcionOriginal) {
            return response()->json([
                'status' => 'error',
                'message' => 'Opción original no encontrada'
            ], 404);
        }

        // Crear nueva opción basada en la original
        $nuevaOpcionData = [
            'opc_nom' => $validated['nuevo_nombre'],
            'ico_id' => $opcionOriginal->ico_id,
            'opc_componente' => $opcionOriginal->opc_componente,
            'opc_eje' => $opcionOriginal->opc_eje,
            'opc_ventana_directa' => $opcionOriginal->opc_ventana_directa,
            'opc_tipo_vista' => $opcionOriginal->opc_tipo_vista ?? 'CRUD',
            'opc_botones_personalizados' => $opcionOriginal->opc_botones_personalizados ?? true,
            'opc_est' => true
        ];

        $nuevaOpcionId = DB::table('tbl_opc')->insertGetId($nuevaOpcionData, 'opc_id');

        // Copiar asignaciones de submenús si se solicita
        if ($validated['copiar_submenus'] ?? true) {
            $submenusOriginales = DB::table('tbl_sub_opc')
                ->where('opc_id', $id)
                ->get();

            foreach ($submenusOriginales as $submenu) {
                DB::table('tbl_sub_opc')->insert([
                    'sub_id' => $submenu->sub_id,
                    'opc_id' => $nuevaOpcionId,
                    'sub_opc_orden' => $submenu->sub_opc_orden,
                    'sub_opc_activo' => $submenu->sub_opc_activo
                ]);
            }
        }

        // *** NUEVA FUNCIONALIDAD: Copiar botones si se solicita ***
        if ($validated['copiar_botones'] ?? true) {
            $botonesOriginales = DB::table('tbl_opc_bot')
                ->where('opc_id', $id)
                ->where('opc_bot_activo', true)
                ->get();

            foreach ($botonesOriginales as $boton) {
                DB::table('tbl_opc_bot')->insert([
                    'opc_id' => $nuevaOpcionId,
                    'bot_id' => $boton->bot_id,
                    'opc_bot_requerido' => $boton->opc_bot_requerido,
                    'opc_bot_orden' => $boton->opc_bot_orden,
                    'opc_bot_activo' => true
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Opción duplicada correctamente con sus botones',
            'nueva_opcion_id' => $nuevaOpcionId,
            'nombre_original' => $opcionOriginal->opc_nom,
            'nuevo_nombre' => $validated['nuevo_nombre']
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Error al duplicar la opción: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Obtener estadísticas de botones por opción
 */
public function getButtonStats()
{
    try {
        $stats = [
            'opciones_con_botones' => DB::table('tbl_opc')
                ->join('tbl_opc_bot', 'tbl_opc.opc_id', '=', 'tbl_opc_bot.opc_id')
                ->where('tbl_opc.opc_activo', true)
                ->where('tbl_opc_bot.opc_bot_activo', true)
                ->distinct('tbl_opc.opc_id')
                ->count(),
            
            'opciones_sin_botones' => DB::table('tbl_opc')
                ->leftJoin('tbl_opc_bot', 'tbl_opc.opc_id', '=', 'tbl_opc_bot.opc_id')
                ->where('tbl_opc.opc_activo', true)
                ->whereNull('tbl_opc_bot.opc_id')
                ->count(),
            
            'promedio_botones_por_opcion' => round(
                DB::table('tbl_opc_bot')
                    ->where('opc_bot_activo', true)
                    ->count() / 
                DB::table('tbl_opc')
                    ->where('opc_activo', true)
                    ->count(), 2
            ),
            
            'botones_mas_usados' => DB::table('tbl_opc_bot')
                ->join('tbl_bot', 'tbl_opc_bot.bot_id', '=', 'tbl_bot.bot_id')
                ->where('tbl_opc_bot.opc_bot_activo', true)
                ->where('tbl_bot.bot_activo', true)
                ->groupBy('tbl_bot.bot_id', 'tbl_bot.bot_nom', 'tbl_bot.bot_codigo')
                ->select(
                    'tbl_bot.bot_nom',
                    'tbl_bot.bot_codigo',
                    DB::raw('count(*) as uso_count')
                )
                ->orderByDesc('uso_count')
                ->limit(5)
                ->get()
        ];

        return response()->json([
            'status' => 'success',
            'stats' => $stats
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener estadísticas de botones: ' . $e->getMessage()
        ], 500);
    }
}
}