<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ButtonController extends Controller
{
    /**
     * Listar todos los botones disponibles
     */
    public function index()
    {
        try {
            $botones = DB::table('tbl_bot')
                ->join('tbl_ico', 'tbl_bot.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_bot.bot_id',
                    'tbl_bot.bot_nom',
                    'tbl_bot.bot_codigo',
                    'tbl_bot.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_bot.bot_color',
                    'tbl_bot.bot_tooltip',
                    'tbl_bot.bot_confirmacion',
                    'tbl_bot.bot_mensaje_confirmacion',
                    'tbl_bot.bot_orden',
                    'tbl_bot.bot_activo'
                )
                ->orderBy('tbl_bot.bot_orden')
                ->get();

            return response()->json([
                'status' => 'success',
                'botones' => $botones
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener botones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo botón
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'bot_nom' => 'required|string|max:100',
            'bot_codigo' => 'required|string|max:20|unique:tbl_bot,bot_codigo',
            'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
            'bot_color' => 'nullable|string|max:20',
            'bot_tooltip' => 'nullable|string|max:200',
            'bot_confirmacion' => 'boolean',
            'bot_mensaje_confirmacion' => 'nullable|string',
            'bot_orden' => 'integer|min:0'
        ]);

        try {
            $botonData = [
                'bot_nom' => $validated['bot_nom'],
                'bot_codigo' => strtoupper($validated['bot_codigo']),
                'ico_id' => $validated['ico_id'] ?? null,
                'bot_color' => $validated['bot_color'] ?? '#007bff',
                'bot_tooltip' => $validated['bot_tooltip'] ?? null,
                'bot_confirmacion' => $validated['bot_confirmacion'] ?? false,
                'bot_mensaje_confirmacion' => $validated['bot_mensaje_confirmacion'] ?? null,
                'bot_orden' => $validated['bot_orden'] ?? 0,
                'bot_activo' => true
            ];

            $botonId = DB::table('tbl_bot')->insertGetId($botonData, 'bot_id');

            return response()->json([
                'status' => 'success',
                'message' => 'Botón creado correctamente',
                'boton_id' => $botonId
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear el botón: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un botón específico
     */
    public function show($id)
    {
        try {
            $boton = DB::table('tbl_bot')
                ->join('tbl_ico', 'tbl_bot.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_bot.bot_id',
                    'tbl_bot.bot_nom',
                    'tbl_bot.bot_codigo',
                    'tbl_bot.ico_id',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria',
                    'tbl_bot.bot_color',
                    'tbl_bot.bot_tooltip',
                    'tbl_bot.bot_confirmacion',
                    'tbl_bot.bot_mensaje_confirmacion',
                    'tbl_bot.bot_orden',
                    'tbl_bot.bot_activo'
                )
                ->where('tbl_bot.bot_id', $id)
                ->first();

            if (!$boton) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Botón no encontrado'
                ], 404);
            }

            // Obtener opciones que usan este botón
            $opciones = DB::table('tbl_opc')
                ->join('tbl_opc_bot', 'tbl_opc.opc_id', '=', 'tbl_opc_bot.opc_id')
                ->select(
                    'tbl_opc.opc_id',
                    'tbl_opc.opc_nom',
                    'tbl_opc_bot.opc_bot_requerido',
                    'tbl_opc_bot.opc_bot_orden'
                )
                ->where('tbl_opc_bot.bot_id', $id)
                ->where('tbl_opc_bot.opc_bot_activo', true)
                ->orderBy('tbl_opc.opc_nom')
                ->get();

            $boton->opciones = $opciones;

            return response()->json([
                'status' => 'success',
                'boton' => $boton
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el botón: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un botón
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'bot_nom' => 'required|string|max:100',
            'bot_codigo' => 'required|string|max:20|unique:tbl_bot,bot_codigo,' . $id . ',bot_id',
            'ico_id' => 'nullable|integer|exists:tbl_ico,ico_id',
            'bot_color' => 'nullable|string|max:20',
            'bot_tooltip' => 'nullable|string|max:200',
            'bot_confirmacion' => 'boolean',
            'bot_mensaje_confirmacion' => 'nullable|string',
            'bot_orden' => 'integer|min:0'
        ]);

        try {
            $boton = DB::table('tbl_bot')->where('bot_id', $id)->first();
            
            if (!$boton) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Botón no encontrado'
                ], 404);
            }

            $botonData = [
                'bot_nom' => $validated['bot_nom'],
                'bot_codigo' => strtoupper($validated['bot_codigo']),
                'ico_id' => $validated['ico_id'] ?? null,
                'bot_color' => $validated['bot_color'] ?? $boton->bot_color,
                'bot_tooltip' => $validated['bot_tooltip'] ?? $boton->bot_tooltip,
                'bot_confirmacion' => $validated['bot_confirmacion'] ?? $boton->bot_confirmacion,
                'bot_mensaje_confirmacion' => $validated['bot_mensaje_confirmacion'] ?? $boton->bot_mensaje_confirmacion,
                'bot_orden' => $validated['bot_orden'] ?? $boton->bot_orden
            ];

            DB::table('tbl_bot')
                ->where('bot_id', $id)
                ->update($botonData);

            return response()->json([
                'status' => 'success',
                'message' => 'Botón actualizado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar el botón: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar el estado de un botón (activar/desactivar)
     */
    public function toggleStatus($id)
    {
        try {
            $boton = DB::table('tbl_bot')->where('bot_id', $id)->first();
            
            if (!$boton) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Botón no encontrado'
                ], 404);
            }

            $newStatus = !$boton->bot_activo;
            
            DB::table('tbl_bot')
                ->where('bot_id', $id)
                ->update(['bot_activo' => $newStatus]);

            $statusText = $newStatus ? 'activado' : 'desactivado';

            return response()->json([
                'status' => 'success',
                'message' => "Botón {$statusText} correctamente",
                'new_status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar el estado del botón: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un botón
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            
            $boton = DB::table('tbl_bot')->where('bot_id', $id)->first();
            
            if (!$boton) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Botón no encontrado'
                ], 404);
            }

            // Verificar si está siendo usado en opciones
            $usoCount = DB::table('tbl_opc_bot')
                ->where('bot_id', $id)
                ->count();
                
            if ($usoCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede eliminar el botón porque está siendo usado en opciones'
                ], 400);
            }

            // Eliminar permisos asociados
            DB::table('tbl_perm_bot_perfil')->where('bot_id', $id)->delete();
            DB::table('tbl_perm_bot_usuario')->where('bot_id', $id)->delete();

            // Eliminar el botón
            DB::table('tbl_bot')->where('bot_id', $id)->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Botón eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar el botón: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener botones disponibles para una opción específica
     */
    public function getByOption($opcionId)
    {
        try {
            $opcion = DB::table('tbl_opc')->where('opc_id', $opcionId)->first();
            
            if (!$opcion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Opción no encontrada'
                ], 404);
            }

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
                    'tbl_opc_bot.opc_bot_orden'
                )
                ->where('tbl_opc_bot.opc_id', $opcionId)
                ->where('tbl_opc_bot.opc_bot_activo', true)
                ->where('tbl_bot.bot_activo', true)
                ->orderBy('tbl_opc_bot.opc_bot_orden')
                ->orderBy('tbl_bot.bot_orden')
                ->get();

            return response()->json([
                'status' => 'success',
                'opcion_nombre' => $opcion->opc_nom,
                'botones' => $botones
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener botones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar/desasignar botones a una opción
     */
    public function assignToOption(Request $request, $opcionId)
    {
        $validated = $request->validate([
            'boton_ids' => 'required|array',
            'boton_ids.*' => 'integer|exists:tbl_bot,bot_id'
        ]);

        try {
            DB::beginTransaction();

            $opcion = DB::table('tbl_opc')->where('opc_id', $opcionId)->first();
            
            if (!$opcion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Opción no encontrada'
                ], 404);
            }

            // Eliminar asignaciones actuales
            DB::table('tbl_opc_bot')
                ->where('opc_id', $opcionId)
                ->delete();

            // Crear nuevas asignaciones
            foreach ($validated['boton_ids'] as $index => $botonId) {
                DB::table('tbl_opc_bot')->insert([
                    'opc_id' => $opcionId,
                    'bot_id' => $botonId,
                    'opc_bot_orden' => $index + 1,
                    'opc_bot_activo' => true
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Botones asignados correctamente a la opción'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al asignar botones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener todos los botones con información de uso
     */
    public function getAllWithUsage()
    {
        try {
            $botones = DB::table('tbl_bot')
                ->join('tbl_ico', 'tbl_bot.ico_id', '=', 'tbl_ico.ico_id', 'left')
                ->select(
                    'tbl_bot.bot_id',
                    'tbl_bot.bot_nom',
                    'tbl_bot.bot_codigo',
                    'tbl_bot.bot_color',
                    'tbl_bot.bot_tooltip',
                    'tbl_bot.bot_confirmacion',
                    'tbl_bot.bot_orden',
                    'tbl_bot.bot_activo',
                    'tbl_ico.ico_nom as ico_nombre',
                    'tbl_ico.ico_lib as ico_libreria'
                )
                ->orderBy('tbl_bot.bot_orden')
                ->get();

            // Para cada botón, obtener información de uso
            foreach ($botones as $boton) {
                $uso = DB::table('tbl_opc_bot')
                    ->join('tbl_opc', 'tbl_opc_bot.opc_id', '=', 'tbl_opc.opc_id')
                    ->where('tbl_opc_bot.bot_id', $boton->bot_id)
                    ->where('tbl_opc_bot.opc_bot_activo', true)
                    ->count();
                
                $boton->uso_count = $uso;
            }

            return response()->json([
                'status' => 'success',
                'botones' => $botones
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener botones con uso: ' . $e->getMessage()
            ], 500);
        }
    }
}