<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LogAccesoController extends Controller
{
    public function registrarIntentoFallido(Request $request)
    {

        try {
            $validator = Validator::make($request->all(), [
                'usu_id' => 'required|integer|exists:tbl_usu,usu_id',
                'oficin_codigo' => 'required|integer|exists:gaf_oficin,oficin_codigo',
                'tipo_intento' => 'required|string|in:FUERA_HORARIO,SIN_HORARIO,OFICINA_CERRADA',
                'fecha_intento' => 'nullable|date_format:Y-m-d H:i:s',
                'hora_intento' => 'nullable|date_format:H:i:s',
                'ip_address' => 'nullable|ip',
                'user_agent' => 'nullable|string',
                'horario_esperado_inicio' => 'nullable|date_format:H:i:s',
                'horario_esperado_fin' => 'nullable|date_format:H:i:s',
                'jornada' => 'nullable|string|in:MATUTINA,NOCTURNA',
                'observaciones' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $now = Carbon::now();
            $fechaIntento = $request->fecha_intento ? 
                Carbon::parse($request->fecha_intento) : $now;
            
            $logData = [
                'logacc_usu_id' => $request->usu_id,
                'logacc_oficin_codigo' => $request->oficin_codigo,
                'logacc_fecha_intento' => $fechaIntento,
                'logacc_hora_intento' => $request->hora_intento ?: $now->format('H:i:s'),
                'logacc_dia_semana' => $fechaIntento->dayOfWeekIso,
                'logacc_tipo_intento' => $request->tipo_intento,
                'logacc_ip_address' => $request->ip_address ?: $request->ip(),
                'logacc_user_agent' => $request->user_agent ?: $request->userAgent(),
                'logacc_horario_esperado_inicio' => $request->horario_esperado_inicio,
                'logacc_horario_esperado_fin' => $request->horario_esperado_fin,
                'logacc_jornada' => $request->jornada,
                'logacc_observaciones' => $request->observaciones
            ];

            DB::beginTransaction();

            $logId = DB::table('gaf_logacc')->insertGetId($logData);

            DB::commit();

            Log::warning("🚫 Intento de acceso fallido registrado:", [
                'log_id' => $logId,
                'usuario_id' => $request->usu_id,
                'oficina_id' => $request->oficin_codigo,
                'tipo' => $request->tipo_intento,
                'ip' => $logData['logacc_ip_address']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Intento de acceso fallido registrado',
                'data' => [
                    'log_id' => $logId,
                    'fecha_registro' => $fechaIntento->format('Y-m-d H:i:s'),
                    'tipo_intento' => $request->tipo_intento
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error registrando intento fallido: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al registrar intento fallido: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener logs de acceso por oficina
     * GET /api/oficinas/{oficinaId}/logs-acceso
     */
    public function logsPorOficina($oficinaId, Request $request)
    {
        try {
            Log::info("📋 Obteniendo logs de acceso para oficina {$oficinaId}");
            
            // Verificar que la oficina existe
            $oficina = DB::table('gaf_oficin')
                ->where('oficin_codigo', $oficinaId)
                ->first();

            if (!$oficina) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oficina no encontrada',
                    'data' => null
                ], 404);
            }

            $perPage = $request->get('per_page', 15);
            $fechaInicio = $request->get('fecha_inicio');
            $fechaFin = $request->get('fecha_fin');
            $tipoIntento = $request->get('tipo_intento');
            $usuarioId = $request->get('usuario_id');

            $query = DB::table('gaf_logacc')
                ->leftJoin('tbl_usu', 'gaf_logacc.logacc_usu_id', '=', 'tbl_usu.usu_id')
                ->leftJoin('gaf_diasem', 'gaf_logacc.logacc_dia_semana', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_logacc.logacc_oficin_codigo', $oficinaId)
                ->select(
                    'gaf_logacc.*',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'gaf_diasem.diasem_nombre',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_completo")
                );

            // Filtros
            if ($fechaInicio) {
                $query->whereDate('gaf_logacc.logacc_fecha_intento', '>=', $fechaInicio);
            }

            if ($fechaFin) {
                $query->whereDate('gaf_logacc.logacc_fecha_intento', '<=', $fechaFin);
            }

            if ($tipoIntento) {
                $query->where('gaf_logacc.logacc_tipo_intento', $tipoIntento);
            }

            if ($usuarioId) {
                $query->where('gaf_logacc.logacc_usu_id', $usuarioId);
            }

            $logs = $query->orderBy('gaf_logacc.logacc_fecha_intento', 'desc')
                ->paginate($perPage);

            // Estadísticas
            $stats = [
                'total_intentos' => DB::table('gaf_logacc')
                    ->where('logacc_oficin_codigo', $oficinaId)
                    ->count(),
                'intentos_hoy' => DB::table('gaf_logacc')
                    ->where('logacc_oficin_codigo', $oficinaId)
                    ->whereDate('logacc_fecha_intento', Carbon::today())
                    ->count(),
                'intentos_esta_semana' => DB::table('gaf_logacc')
                    ->where('logacc_oficin_codigo', $oficinaId)
                    ->whereBetween('logacc_fecha_intento', [
                        Carbon::now()->startOfWeek(),
                        Carbon::now()->endOfWeek()
                    ])
                    ->count(),
                'usuarios_frecuentes' => DB::table('gaf_logacc')
                    ->join('tbl_usu', 'gaf_logacc.logacc_usu_id', '=', 'tbl_usu.usu_id')
                    ->where('gaf_logacc.logacc_oficin_codigo', $oficinaId)
                    ->select(
                        'tbl_usu.usu_id',
                        'tbl_usu.usu_nom',
                        'tbl_usu.usu_ape',
                        'tbl_usu.usu_cor',
                        DB::raw('COUNT(*) as total_intentos'),
                        DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_completo")
                    )
                    ->groupBy('tbl_usu.usu_id', 'tbl_usu.usu_nom', 'tbl_usu.usu_ape', 'tbl_usu.usu_cor')
                    ->orderBy('total_intentos', 'desc')
                    ->limit(5)
                    ->get(),
                'tipos_intento' => DB::table('gaf_logacc')
                    ->where('logacc_oficin_codigo', $oficinaId)
                    ->select(
                        'logacc_tipo_intento',
                        DB::raw('COUNT(*) as cantidad')
                    )
                    ->groupBy('logacc_tipo_intento')
                    ->get()
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Logs de acceso obtenidos correctamente',
                'data' => [
                    'oficina' => [
                        'oficin_codigo' => $oficina->oficin_codigo,
                        'oficin_nombre' => $oficina->oficin_nombre
                    ],
                    'logs' => $logs,
                    'estadisticas' => $stats,
                    'filtros_aplicados' => [
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                        'tipo_intento' => $tipoIntento,
                        'usuario_id' => $usuarioId
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo logs por oficina: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener logs: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener logs de acceso por usuario
     * GET /api/usuarios/{usuarioId}/logs-acceso
     */
    public function logsPorUsuario($usuarioId, Request $request)
    {
        try {
            Log::info("📋 Obteniendo logs de acceso para usuario {$usuarioId}");
            
            // Verificar que el usuario existe
            $usuario = DB::table('tbl_usu')
                ->where('usu_id', $usuarioId)
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            $perPage = $request->get('per_page', 15);
            $fechaInicio = $request->get('fecha_inicio');
            $fechaFin = $request->get('fecha_fin');
            $tipoIntento = $request->get('tipo_intento');
            $oficinaId = $request->get('oficina_id');

            $query = DB::table('gaf_logacc')
                ->leftJoin('gaf_oficin', 'gaf_logacc.logacc_oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->leftJoin('gaf_diasem', 'gaf_logacc.logacc_dia_semana', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_logacc.logacc_usu_id', $usuarioId)
                ->select(
                    'gaf_logacc.*',
                    'gaf_oficin.oficin_nombre',
                    'gaf_oficin.oficin_direccion',
                    'gaf_diasem.diasem_nombre'
                );

            // Filtros
            if ($fechaInicio) {
                $query->whereDate('gaf_logacc.logacc_fecha_intento', '>=', $fechaInicio);
            }

            if ($fechaFin) {
                $query->whereDate('gaf_logacc.logacc_fecha_intento', '<=', $fechaFin);
            }

            if ($tipoIntento) {
                $query->where('gaf_logacc.logacc_tipo_intento', $tipoIntento);
            }

            if ($oficinaId) {
                $query->where('gaf_logacc.logacc_oficin_codigo', $oficinaId);
            }

            $logs = $query->orderBy('gaf_logacc.logacc_fecha_intento', 'desc')
                ->paginate($perPage);

            // Estadísticas del usuario
            $stats = [
                'total_intentos' => DB::table('gaf_logacc')
                    ->where('logacc_usu_id', $usuarioId)
                    ->count(),
                'intentos_ultima_semana' => DB::table('gaf_logacc')
                    ->where('logacc_usu_id', $usuarioId)
                    ->where('logacc_fecha_intento', '>=', Carbon::now()->subWeek())
                    ->count(),
                'oficinas_mas_intentos' => DB::table('gaf_logacc')
                    ->join('gaf_oficin', 'gaf_logacc.logacc_oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                    ->where('gaf_logacc.logacc_usu_id', $usuarioId)
                    ->select(
                        'gaf_oficin.oficin_codigo',
                        'gaf_oficin.oficin_nombre',
                        DB::raw('COUNT(*) as total_intentos')
                    )
                    ->groupBy('gaf_oficin.oficin_codigo', 'gaf_oficin.oficin_nombre')
                    ->orderBy('total_intentos', 'desc')
                    ->limit(5)
                    ->get(),
                'horarios_frecuentes' => DB::table('gaf_logacc')
                    ->where('logacc_usu_id', $usuarioId)
                    ->select(
                        DB::raw('EXTRACT(HOUR FROM logacc_hora_intento) as hora'),
                        DB::raw('COUNT(*) as cantidad')
                    )
                    ->groupBy(DB::raw('EXTRACT(HOUR FROM logacc_hora_intento)'))
                    ->orderBy('cantidad', 'desc')
                    ->limit(5)
                    ->get()
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Logs de acceso del usuario obtenidos correctamente',
                'data' => [
                    'usuario' => [
                        'usu_id' => $usuario->usu_id,
                        'nombre_completo' => trim($usuario->usu_nom . ' ' . $usuario->usu_ape),
                        'usu_cor' => $usuario->usu_cor,
                        'usu_ced' => $usuario->usu_ced
                    ],
                    'logs' => $logs,
                    'estadisticas' => $stats,
                    'filtros_aplicados' => [
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                        'tipo_intento' => $tipoIntento,
                        'oficina_id' => $oficinaId
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo logs por usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener logs del usuario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener estadísticas generales de logs de acceso
     * GET /api/logs/estadisticas-generales
     */
    public function estadisticasGenerales(Request $request)
    {
        try {
            Log::info("📊 Obteniendo estadísticas generales de logs de acceso");
            
            $fechaInicio = $request->get('fecha_inicio', Carbon::now()->subMonth()->format('Y-m-d'));
            $fechaFin = $request->get('fecha_fin', Carbon::now()->format('Y-m-d'));

            $baseQuery = DB::table('gaf_logacc')
                ->whereBetween('logacc_fecha_intento', [$fechaInicio, $fechaFin]);

            $stats = [
                'total_intentos_fallidos' => $baseQuery->count(),
                'intentos_por_tipo' => DB::table('gaf_logacc')
                    ->whereBetween('logacc_fecha_intento', [$fechaInicio, $fechaFin])
                    ->select(
                        'logacc_tipo_intento',
                        DB::raw('COUNT(*) as cantidad'),
                        DB::raw('ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM gaf_logacc WHERE logacc_fecha_intento BETWEEN ? AND ?)), 2) as porcentaje')
                    )
                    ->addBinding([$fechaInicio, $fechaFin])
                    ->groupBy('logacc_tipo_intento')
                    ->orderBy('cantidad', 'desc')
                    ->get(),
                'intentos_por_dia' => DB::table('gaf_logacc')
                    ->whereBetween('logacc_fecha_intento', [$fechaInicio, $fechaFin])
                    ->select(
                        DB::raw('DATE(logacc_fecha_intento) as fecha'),
                        DB::raw('COUNT(*) as cantidad')
                    )
                    ->groupBy(DB::raw('DATE(logacc_fecha_intento)'))
                    ->orderBy('fecha', 'desc')
                    ->limit(30)
                    ->get(),
                'usuarios_mas_intentos' => DB::table('gaf_logacc')
                    ->join('tbl_usu', 'gaf_logacc.logacc_usu_id', '=', 'tbl_usu.usu_id')
                    ->whereBetween('gaf_logacc.logacc_fecha_intento', [$fechaInicio, $fechaFin])
                    ->select(
                        'tbl_usu.usu_id',
                        'tbl_usu.usu_nom',
                        'tbl_usu.usu_ape',
                        'tbl_usu.usu_cor',
                        DB::raw('COUNT(*) as total_intentos'),
                        DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_completo")
                    )
                    ->groupBy('tbl_usu.usu_id', 'tbl_usu.usu_nom', 'tbl_usu.usu_ape', 'tbl_usu.usu_cor')
                    ->orderBy('total_intentos', 'desc')
                    ->limit(10)
                    ->get(),
                'oficinas_mas_intentos' => DB::table('gaf_logacc')
                    ->join('gaf_oficin', 'gaf_logacc.logacc_oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                    ->whereBetween('gaf_logacc.logacc_fecha_intento', [$fechaInicio, $fechaFin])
                    ->select(
                        'gaf_oficin.oficin_codigo',
                        'gaf_oficin.oficin_nombre',
                        DB::raw('COUNT(*) as total_intentos')
                    )
                    ->groupBy('gaf_oficin.oficin_codigo', 'gaf_oficin.oficin_nombre')
                    ->orderBy('total_intentos', 'desc')
                    ->limit(10)
                    ->get(),
                'patrones_horarios' => DB::table('gaf_logacc')
                    ->whereBetween('logacc_fecha_intento', [$fechaInicio, $fechaFin])
                    ->select(
                        DB::raw('EXTRACT(HOUR FROM logacc_hora_intento) as hora'),
                        DB::raw('COUNT(*) as cantidad')
                    )
                    ->groupBy(DB::raw('EXTRACT(HOUR FROM logacc_hora_intento)'))
                    ->orderBy('hora')
                    ->get(),
                'resumen_ips' => DB::table('gaf_logacc')
                    ->whereBetween('logacc_fecha_intento', [$fechaInicio, $fechaFin])
                    ->whereNotNull('logacc_ip_address')
                    ->select(
                        'logacc_ip_address',
                        DB::raw('COUNT(*) as intentos'),
                        DB::raw('COUNT(DISTINCT logacc_usu_id) as usuarios_distintos')
                    )
                    ->groupBy('logacc_ip_address')
                    ->orderBy('intentos', 'desc')
                    ->limit(10)
                    ->get()
            ];

            // Calcular tendencias
            $fechaInicioPrevio = Carbon::parse($fechaInicio)->subDays(
                Carbon::parse($fechaFin)->diffInDays(Carbon::parse($fechaInicio))
            )->format('Y-m-d');
            
            $intentosPeriodoAnterior = DB::table('gaf_logacc')
                ->whereBetween('logacc_fecha_intento', [$fechaInicioPrevio, $fechaInicio])
                ->count();

            $tendencia = [
                'periodo_actual' => $stats['total_intentos_fallidos'],
                'periodo_anterior' => $intentosPeriodoAnterior,
                'diferencia' => $stats['total_intentos_fallidos'] - $intentosPeriodoAnterior,
                'porcentaje_cambio' => $intentosPeriodoAnterior > 0 ? 
                    round((($stats['total_intentos_fallidos'] - $intentosPeriodoAnterior) / $intentosPeriodoAnterior) * 100, 2) : 0
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Estadísticas generales obtenidas correctamente',
                'data' => [
                    'periodo' => [
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                        'dias_analizados' => Carbon::parse($fechaFin)->diffInDays(Carbon::parse($fechaInicio)) + 1
                    ],
                    'estadisticas' => $stats,
                    'tendencias' => $tendencia,
                    'alertas' => [
                        'usuarios_sospechosos' => $stats['usuarios_mas_intentos']->where('total_intentos', '>', 10)->count(),
                        'ips_multiples_usuarios' => $stats['resumen_ips']->where('usuarios_distintos', '>', 3)->count(),
                        'picos_horarios' => $stats['patrones_horarios']->where('cantidad', '>', 20)->count()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo estadísticas generales: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Limpiar logs antiguos
     * DELETE /api/logs/limpiar?dias=30
     */
    public function limpiarLogsAntiguos(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'dias' => 'required|integer|min:1|max:365'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $dias = $request->get('dias');
            $fechaLimite = Carbon::now()->subDays($dias);

            Log::info("🧹 Iniciando limpieza de logs anteriores a: " . $fechaLimite->format('Y-m-d'));

            // Contar registros a eliminar
            $registrosAEliminar = DB::table('gaf_logacc')
                ->where('logacc_fecha_intento', '<', $fechaLimite)
                ->count();

            if ($registrosAEliminar === 0) {
                return response()->json([
                    'status' => 'info',
                    'message' => 'No hay logs antiguos para eliminar',
                    'data' => [
                        'fecha_limite' => $fechaLimite->format('Y-m-d'),
                        'registros_eliminados' => 0
                    ]
                ]);
            }

            DB::beginTransaction();

            // Eliminar registros antiguos
            DB::table('gaf_logacc')
                ->where('logacc_fecha_intento', '<', $fechaLimite)
                ->delete();

            DB::commit();

            Log::info("✅ Logs antiguos eliminados:", [
                'registros_eliminados' => $registrosAEliminar,
                'fecha_limite' => $fechaLimite->format('Y-m-d')
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se eliminaron {$registrosAEliminar} registros de logs antiguos",
                'data' => [
                    'fecha_limite' => $fechaLimite->format('Y-m-d'),
                    'registros_eliminados' => $registrosAEliminar,
                    'dias_conservados' => $dias
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error limpiando logs antiguos: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al limpiar logs antiguos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Exportar logs a CSV
     * GET /api/logs/exportar?formato=csv&fecha_inicio=2024-01-01&fecha_fin=2024-12-31
     */
    public function exportarLogs(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'formato' => 'required|string|in:csv,json',
                'fecha_inicio' => 'nullable|date_format:Y-m-d',
                'fecha_fin' => 'nullable|date_format:Y-m-d',
                'oficina_id' => 'nullable|integer|exists:gaf_oficin,oficin_codigo',
                'usuario_id' => 'nullable|integer|exists:tbl_usu,usu_id',
                'tipo_intento' => 'nullable|string|in:FUERA_HORARIO,SIN_HORARIO,OFICINA_CERRADA'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $formato = $request->get('formato');
            $fechaInicio = $request->get('fecha_inicio', Carbon::now()->subMonth()->format('Y-m-d'));
            $fechaFin = $request->get('fecha_fin', Carbon::now()->format('Y-m-d'));

            $query = DB::table('gaf_logacc')
                ->leftJoin('tbl_usu', 'gaf_logacc.logacc_usu_id', '=', 'tbl_usu.usu_id')
                ->leftJoin('gaf_oficin', 'gaf_logacc.logacc_oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->leftJoin('gaf_diasem', 'gaf_logacc.logacc_dia_semana', '=', 'gaf_diasem.diasem_codigo')
                ->whereBetween('gaf_logacc.logacc_fecha_intento', [$fechaInicio, $fechaFin])
                ->select(
                    'gaf_logacc.logacc_id',
                    'gaf_logacc.logacc_fecha_intento',
                    'gaf_logacc.logacc_hora_intento',
                    'gaf_logacc.logacc_tipo_intento',
                    'gaf_logacc.logacc_ip_address',
                    'gaf_logacc.logacc_jornada',
                    'gaf_logacc.logacc_observaciones',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_cor',
                    'tbl_usu.usu_ced',
                    'gaf_oficin.oficin_nombre',
                    'gaf_oficin.oficin_direccion',
                    'gaf_diasem.diasem_nombre',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_completo")
                );

            // Aplicar filtros adicionales
            if ($request->has('oficina_id')) {
                $query->where('gaf_logacc.logacc_oficin_codigo', $request->oficina_id);
            }

            if ($request->has('usuario_id')) {
                $query->where('gaf_logacc.logacc_usu_id', $request->usuario_id);
            }

            if ($request->has('tipo_intento')) {
                $query->where('gaf_logacc.logacc_tipo_intento', $request->tipo_intento);
            }

            $logs = $query->orderBy('gaf_logacc.logacc_fecha_intento', 'desc')->get();

            if ($logs->isEmpty()) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'No se encontraron logs para exportar con los filtros especificados',
                    'data' => null
                ]);
            }

            $nombreArchivo = 'logs_acceso_' . $fechaInicio . '_' . $fechaFin . '.' . $formato;

            if ($formato === 'csv') {
                $csv = "ID,Fecha,Hora,Tipo Intento,Usuario,Email,Cedula,Oficina,Dia Semana,IP,Jornada,Observaciones\n";
                
                foreach ($logs as $log) {
                    $csv .= sprintf(
                        "%d,%s,%s,%s,\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                        $log->logacc_id,
                        $log->logacc_fecha_intento,
                        $log->logacc_hora_intento,
                        $log->logacc_tipo_intento,
                        $log->nombre_completo,
                        $log->usu_cor,
                        $log->usu_ced,
                        $log->oficin_nombre,
                        $log->diasem_nombre,
                        $log->logacc_ip_address,
                        $log->logacc_jornada,
                        str_replace('"', '""', $log->logacc_observaciones)
                    );
                }

                return response($csv)
                    ->header('Content-Type', 'text/csv')
                    ->header('Content-Disposition', 'attachment; filename="' . $nombreArchivo . '"');
            } else {
                // Formato JSON
                return response()->json([
                    'status' => 'success',
                    'message' => 'Logs exportados correctamente',
                    'data' => [
                        'logs' => $logs,
                        'metadata' => [
                            'fecha_inicio' => $fechaInicio,
                            'fecha_fin' => $fechaFin,
                            'total_registros' => $logs->count(),
                            'fecha_exportacion' => Carbon::now()->format('Y-m-d H:i:s')
                        ]
                    ]
                ])
                ->header('Content-Disposition', 'attachment; filename="' . $nombreArchivo . '"');
            }

        } catch (\Exception $e) {
            Log::error("❌ Error exportando logs: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar logs: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
}