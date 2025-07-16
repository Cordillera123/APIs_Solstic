<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HorarioUsuarioController extends Controller
{
    /**
     * Obtener horarios de un usuario específico
     * GET /api/usuarios/{usuarioId}/horarios
     */
  public function index($usuarioId, Request $request)
{
    try {
        Log::info("🕐 Obteniendo horarios para usuario ID: {$usuarioId}");
        
        // Verificar que el usuario existe
        $usuario = DB::table('tbl_usu')
            ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
            ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
            ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
            ->where('tbl_usu.usu_id', $usuarioId)
            ->select(
                'tbl_usu.usu_id',
                'tbl_usu.usu_nom',
                'tbl_usu.usu_ape',
                'tbl_usu.usu_cor',
                'tbl_usu.usu_ced',
                'tbl_usu.oficin_codigo',
                'gaf_oficin.oficin_nombre',
                'gaf_oficin.oficin_ctractual',
                'tbl_per.per_nom',
                'tbl_est.est_nom',
                DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_completo")
            )
            ->first();

        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado',
                'data' => null
            ], 404);
        }

        // Fecha de referencia (hoy por defecto, o la fecha especificada)
        $fechaReferencia = $request->get('fecha', Carbon::today()->format('Y-m-d'));
        $fechaConsulta = Carbon::parse($fechaReferencia);

        // Obtener horarios temporales activos para la fecha de referencia
        $horariosTemporales = DB::table('gaf_jorusu_temp')
            ->leftJoin('gaf_diasem', 'gaf_jorusu_temp.temp_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
            ->where('gaf_jorusu_temp.temp_usu_id', $usuarioId)
            ->where('gaf_jorusu_temp.temp_fecha_inicio', '<=', $fechaConsulta)
            ->where('gaf_jorusu_temp.temp_fecha_fin', '>=', $fechaConsulta)
            ->where('gaf_jorusu_temp.temp_activo', true)
            ->select(
                'gaf_jorusu_temp.temp_diasem_codigo',
                'gaf_jorusu_temp.temp_horentrada',
                'gaf_jorusu_temp.temp_horsalida',
                'gaf_jorusu_temp.temp_motivo',
                'gaf_jorusu_temp.temp_tipo',
                'gaf_jorusu_temp.temp_fecha_inicio',
                'gaf_jorusu_temp.temp_fecha_fin',
                'gaf_diasem.diasem_nombre',
                'gaf_diasem.diasem_abreviatura',
                DB::raw("CASE 
                    WHEN EXTRACT(HOUR FROM gaf_jorusu_temp.temp_horentrada) < 12 THEN 'MATUTINA'
                    ELSE 'NOCTURNA'
                END as jornada_tipo")
            )
            ->orderBy('gaf_jorusu_temp.temp_diasem_codigo')
            ->get()
            ->keyBy('temp_diasem_codigo');

        // Obtener horarios personalizados permanentes del usuario
        $horariosPersonalizados = DB::table('gaf_jorusu')
            ->leftJoin('gaf_diasem', 'gaf_jorusu.jorusu_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
            ->where('gaf_jorusu.jorusu_usu_id', $usuarioId)
            ->select(
                'gaf_jorusu.jorusu_diasem_codigo',
                'gaf_jorusu.jorusu_horentrada',
                'gaf_jorusu.jorusu_horsalida',
                'gaf_diasem.diasem_nombre',
                'gaf_diasem.diasem_abreviatura',
                DB::raw("CASE 
                    WHEN EXTRACT(HOUR FROM gaf_jorusu.jorusu_horentrada) < 12 THEN 'MATUTINA'
                    ELSE 'NOCTURNA'
                END as jornada_tipo")
            )
            ->orderBy('gaf_jorusu.jorusu_diasem_codigo')
            ->get()
            ->keyBy('jorusu_diasem_codigo');

        // Obtener horarios de oficina (para herencia)
        $horariosOficina = collect();
        if ($usuario->oficin_codigo) {
            $horariosOficina = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $usuario->oficin_codigo)
                ->where('gaf_jorofi.jorofi_ctrhabil', 1)
                ->select(
                    'gaf_jorofi.jorofi_diasem_codigo',
                    'gaf_jorofi.jorofi_horinicial',
                    'gaf_jorofi.jorofi_horfinal',
                    'gaf_diasem.diasem_nombre',
                    'gaf_diasem.diasem_abreviatura'
                )
                ->orderBy('gaf_jorofi.jorofi_diasem_codigo')
                ->get()
                ->keyBy('jorofi_diasem_codigo');
        }

        // Crear array de días completo (1-7, Lunes a Domingo)
        $diasCompletos = [];
        $diasSemana = DB::table('gaf_diasem')
            ->orderBy('diasem_codigo')
            ->get();

        foreach ($diasSemana as $dia) {
            $horarioTemporal = $horariosTemporales->get($dia->diasem_codigo);
            $horarioPersonalizado = $horariosPersonalizados->get($dia->diasem_codigo);
            $horarioOficina = $horariosOficina->get($dia->diasem_codigo);
            
            // ✅ NUEVA LÓGICA DE PRIORIDAD CON TEMPORALES
            $horarioEfectivo = null;
            $origen = 'SIN_HORARIO';
            
            // 1. PRIORIDAD MÁXIMA: Horario temporal
            if ($horarioTemporal) {
                $horarioEfectivo = [
                    'hora_entrada' => $horarioTemporal->temp_horentrada,
                    'hora_salida' => $horarioTemporal->temp_horsalida,
                    'jornada' => $horarioTemporal->jornada_tipo,
                    'motivo_temporal' => $horarioTemporal->temp_motivo,
                    'tipo_temporal' => $horarioTemporal->temp_tipo,
                    'fecha_inicio_temporal' => $horarioTemporal->temp_fecha_inicio,
                    'fecha_fin_temporal' => $horarioTemporal->temp_fecha_fin
                ];
                $origen = 'TEMPORAL';
            }
            // 2. SEGUNDA PRIORIDAD: Horario personalizado permanente
            elseif ($horarioPersonalizado) {
                $horarioEfectivo = [
                    'hora_entrada' => $horarioPersonalizado->jorusu_horentrada,
                    'hora_salida' => $horarioPersonalizado->jorusu_horsalida,
                    'jornada' => $horarioPersonalizado->jornada_tipo
                ];
                $origen = 'PERSONALIZADO';
            } 
            // 3. TERCERA PRIORIDAD: Horario heredado de oficina
            elseif ($horarioOficina) {
                $horarioEfectivo = [
                    'hora_entrada' => $horarioOficina->jorofi_horinicial,
                    'hora_salida' => $horarioOficina->jorofi_horfinal,
                    'jornada' => Carbon::parse($horarioOficina->jorofi_horinicial)->hour < 12 ? 'MATUTINA' : 'NOCTURNA'
                ];
                $origen = 'HEREDADO_OFICINA';
            }

            $diasCompletos[] = [
                'dia_codigo' => $dia->diasem_codigo,
                'dia_nombre' => trim($dia->diasem_nombre),
                'dia_abreviatura' => trim($dia->diasem_abreviatura),
                
                // ✅ NUEVA INFORMACIÓN DE TEMPORALES
                'tiene_horario_temporal' => $horarioTemporal ? true : false,
                'tiene_horario_personalizado' => $horarioPersonalizado ? true : false,
                'tiene_horario_oficina' => $horarioOficina ? true : false,
                
                // ✅ DETALLE COMPLETO DE HORARIO TEMPORAL
                'horario_temporal' => $horarioTemporal ? [
                    'hora_entrada' => $horarioTemporal->temp_horentrada,
                    'hora_salida' => $horarioTemporal->temp_horsalida,
                    'jornada' => $horarioTemporal->jornada_tipo,
                    'motivo' => $horarioTemporal->temp_motivo,
                    'tipo' => $horarioTemporal->temp_tipo,
                    'fecha_inicio' => $horarioTemporal->temp_fecha_inicio,
                    'fecha_fin' => $horarioTemporal->temp_fecha_fin,
                    'dias_restantes' => Carbon::parse($horarioTemporal->temp_fecha_fin)->diffInDays($fechaConsulta, false),
                    'formato_visual' => Carbon::parse($horarioTemporal->temp_horentrada)->format('H:i') . ' - ' . 
                                      Carbon::parse($horarioTemporal->temp_horsalida)->format('H:i') . 
                                      ' (' . $horarioTemporal->temp_tipo . ')'
                ] : null,
                
                'horario_personalizado' => $horarioPersonalizado ? [
                    'hora_entrada' => $horarioPersonalizado->jorusu_horentrada,
                    'hora_salida' => $horarioPersonalizado->jorusu_horsalida,
                    'jornada' => $horarioPersonalizado->jornada_tipo,
                    'formato_visual' => Carbon::parse($horarioPersonalizado->jorusu_horentrada)->format('H:i') . ' - ' . 
                                      Carbon::parse($horarioPersonalizado->jorusu_horsalida)->format('H:i')
                ] : null,
                
                'horario_oficina' => $horarioOficina ? [
                    'hora_entrada' => $horarioOficina->jorofi_horinicial,
                    'hora_salida' => $horarioOficina->jorofi_horfinal,
                    'formato_visual' => Carbon::parse($horarioOficina->jorofi_horinicial)->format('H:i') . ' - ' . 
                                      Carbon::parse($horarioOficina->jorofi_horfinal)->format('H:i')
                ] : null,
                
                'horario_efectivo' => $horarioEfectivo,
                'origen_horario' => $origen,
                'puede_acceder' => $horarioEfectivo !== null,
                
                // ✅ INDICADORES VISUALES
                'es_temporal_activo' => $origen === 'TEMPORAL',
                'prioridad_horario' => $origen === 'TEMPORAL' ? 1 : ($origen === 'PERSONALIZADO' ? 2 : ($origen === 'HEREDADO_OFICINA' ? 3 : 4))
            ];
        }

        // ✅ ESTADÍSTICAS ACTUALIZADAS CON TEMPORALES
        $stats = [
            'total_dias_temporales' => $horariosTemporales->count(),
            'total_dias_personalizados' => $horariosPersonalizados->count(),
            'total_dias_heredados' => $horariosOficina->count() - $horariosPersonalizados->count() - $horariosTemporales->count(),
            'total_dias_sin_horario' => 7 - collect($diasCompletos)->where('puede_acceder', true)->count(),
            'dias_operativos' => collect($diasCompletos)->where('puede_acceder', true)->count(),
            'jornadas_matutinas' => collect($diasCompletos)->where('horario_efectivo.jornada', 'MATUTINA')->count(),
            'jornadas_nocturnas' => collect($diasCompletos)->where('horario_efectivo.jornada', 'NOCTURNA')->count(),
            'usuario_operativo' => collect($diasCompletos)->where('puede_acceder', true)->count() > 0,
            'independencia_oficina' => $horariosPersonalizados->count() > 0 || $horariosTemporales->count() > 0,
            
            // ✅ NUEVAS ESTADÍSTICAS DE TEMPORALES
            'tiene_horarios_temporales' => $horariosTemporales->count() > 0,
            'tipos_temporales_activos' => $horariosTemporales->pluck('temp_tipo')->unique()->values(),
            'dias_con_temporal_prioritario' => collect($diasCompletos)->where('es_temporal_activo', true)->count()
        ];

        Log::info("✅ Horarios de usuario obtenidos correctamente:", [
            'usuario_id' => $usuarioId,
            'fecha_referencia' => $fechaReferencia,
            'dias_temporales' => $stats['total_dias_temporales'],
            'dias_personalizados' => $stats['total_dias_personalizados'],
            'dias_operativos' => $stats['dias_operativos']
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Horarios de usuario obtenidos correctamente',
            'data' => [
                'usuario' => $usuario,
                'fecha_referencia' => $fechaReferencia,
                'horarios_por_dia' => $diasCompletos,
                'estadisticas' => $stats,
                
                // ✅ INFORMACIÓN ADICIONAL SOBRE TEMPORALES
                'resumen_temporales' => [
                    'total_activos' => $horariosTemporales->count(),
                    'tipos_presentes' => $horariosTemporales->pluck('temp_tipo')->unique()->values(),
                    'fecha_consulta' => $fechaReferencia
                ]
            ]
        ]);

    } catch (\Exception $e) {
        Log::error("❌ Error obteniendo horarios usuario {$usuarioId}: " . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error al obtener horarios: ' . $e->getMessage(),
            'data' => null
        ], 500);
    }
}

    /**
     * Crear o actualizar horario personalizado para un día específico
     * POST /api/usuarios/{usuarioId}/horarios
     */
    public function store($usuarioId, Request $request)
    {
        try {
            Log::info("🕐 Creando/actualizando horario personalizado para usuario {$usuarioId}");
            
            $validator = Validator::make($request->all(), [
                'dia_codigo' => 'required|integer|between:1,7|exists:gaf_diasem,diasem_codigo',
                'hora_entrada' => 'required|date_format:H:i',
                'hora_salida' => 'required|date_format:H:i|after:hora_entrada',
                'observaciones' => 'nullable|string|max:500',
                'forzar_creacion' => 'boolean' // Para permitir forzar en casos especiales
            ], [
                'dia_codigo.required' => 'El día de la semana es requerido',
                'dia_codigo.between' => 'El día debe estar entre 1 (Lunes) y 7 (Domingo)',
                'dia_codigo.exists' => 'El día seleccionado no es válido',
                'hora_entrada.required' => 'La hora de entrada es requerida',
                'hora_entrada.date_format' => 'La hora de entrada debe tener formato HH:MM',
                'hora_salida.required' => 'La hora de salida es requerida',
                'hora_salida.date_format' => 'La hora de salida debe tener formato HH:MM',
                'hora_salida.after' => 'La hora de salida debe ser posterior a la hora de entrada'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Verificar que el usuario existe y obtener su oficina
            $usuario = DB::table('tbl_usu')
                ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->where('tbl_usu.usu_id', $usuarioId)
                ->select('tbl_usu.*', 'gaf_oficin.oficin_nombre')
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            if (!$usuario->oficin_codigo) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El usuario no tiene oficina asignada',
                    'data' => null
                ], 422);
            }

            // ✅ VALIDACIÓN AUTOMÁTICA CONTRA HORARIO DE OFICINA
            $validacionOficina = $this->validarContraHorarioOficina(
                $usuario->oficin_codigo,
                $request->dia_codigo,
                $request->hora_entrada,
                $request->hora_salida
            );

            if (!$validacionOficina['valido'] && !$request->get('forzar_creacion', false)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El horario personalizado debe estar dentro del rango de la oficina',
                    'data' => [
                        'motivo_rechazo' => $validacionOficina['motivo'],
                        'horario_oficina' => $validacionOficina['horario_oficina'] ?? null,
                        'horario_solicitado' => [
                            'hora_entrada' => $request->hora_entrada,
                            'hora_salida' => $request->hora_salida
                        ],
                        'sugerencias' => $this->generarSugerenciasHorario($validacionOficina)
                    ]
                ], 422);
            }

            DB::beginTransaction();

            $horarioData = [
                'jorusu_usu_id' => $usuarioId,
                'jorusu_diasem_codigo' => $request->dia_codigo,
                'jorusu_horentrada' => $request->hora_entrada,
                'jorusu_horsalida' => $request->hora_salida
            ];

            // Verificar si ya existe horario para este día
            $horarioExistente = DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioId)
                ->where('jorusu_diasem_codigo', $request->dia_codigo)
                ->first();

            $operacion = '';
            if ($horarioExistente) {
                // Actualizar horario existente
                DB::table('gaf_jorusu')
                    ->where('jorusu_usu_id', $usuarioId)
                    ->where('jorusu_diasem_codigo', $request->dia_codigo)
                    ->update($horarioData);
                $operacion = 'actualizado';
            } else {
                // Crear nuevo horario
                DB::table('gaf_jorusu')->insert($horarioData);
                $operacion = 'creado';
            }

            // Obtener información del día configurado
            $diaInfo = DB::table('gaf_diasem')
                ->where('diasem_codigo', $request->dia_codigo)
                ->first();

            $horaEntrada = Carbon::createFromFormat('H:i', $request->hora_entrada);
            $horarioConfigurado = [
                'usuario_id' => $usuarioId,
                'dia_codigo' => $request->dia_codigo,
                'dia_nombre' => trim($diaInfo->diasem_nombre),
                'hora_entrada' => $request->hora_entrada,
                'hora_salida' => $request->hora_salida,
                'jornada' => $horaEntrada->hour < 12 ? 'MATUTINA' : 'NOCTURNA',
                'formato_visual' => "{$request->hora_entrada} - {$request->hora_salida}",
                'observaciones' => $request->observaciones,
                'validacion_oficina' => $validacionOficina,
                'forzado' => $request->get('forzar_creacion', false)
            ];

            DB::commit();

            Log::info("✅ Horario personalizado {$operacion} exitosamente:", [
                'usuario_id' => $usuarioId,
                'dia' => $diaInfo->diasem_nombre,
                'horario' => "{$request->hora_entrada} - {$request->hora_salida}",
                'validacion_oficina' => $validacionOficina['valido']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Horario personalizado {$operacion} exitosamente para " . trim($diaInfo->diasem_nombre),
                'data' => $horarioConfigurado
            ], $horarioExistente ? 200 : 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error creando horario usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear horario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
/**
     * Crear/actualizar múltiples horarios de una vez
     * POST /api/usuarios/{usuarioId}/horarios/batch
     */
    public function storeBatch($usuarioId, Request $request)
    {
        try {
            Log::info("🕐 Creando/actualizando horarios múltiples para usuario {$usuarioId}");
            
            $validator = Validator::make($request->all(), [
                'horarios' => 'required|array|min:1|max:7',
                'horarios.*.dia_codigo' => 'required|integer|between:1,7|exists:gaf_diasem,diasem_codigo',
                'horarios.*.hora_entrada' => 'required|date_format:H:i',
                'horarios.*.hora_salida' => 'required|date_format:H:i',
                'sobrescribir_existentes' => 'boolean',
                'validar_contra_oficina' => 'boolean',
                'forzar_creacion' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Verificar que el usuario existe
            $usuario = DB::table('tbl_usu')
                ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->where('tbl_usu.usu_id', $usuarioId)
                ->select('tbl_usu.*', 'gaf_oficin.oficin_nombre')
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            if (!$usuario->oficin_codigo) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El usuario no tiene oficina asignada',
                    'data' => null
                ], 422);
            }

            // Validar que no haya días duplicados
            $diasEnviados = array_column($request->horarios, 'dia_codigo');
            if (count($diasEnviados) !== count(array_unique($diasEnviados))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No puede enviar horarios duplicados para el mismo día',
                    'data' => null
                ], 422);
            }

            $validarContraOficina = $request->get('validar_contra_oficina', true);
            $forzarCreacion = $request->get('forzar_creacion', false);
            $horariosInvalidos = [];

            // ✅ VALIDAR TODOS LOS HORARIOS CONTRA OFICINA
            if ($validarContraOficina && !$forzarCreacion) {
                foreach ($request->horarios as $index => $horario) {
                    $validacion = $this->validarContraHorarioOficina(
                        $usuario->oficin_codigo,
                        $horario['dia_codigo'],
                        $horario['hora_entrada'],
                        $horario['hora_salida']
                    );

                    if (!$validacion['valido']) {
                        $horariosInvalidos[] = [
                            'indice' => $index,
                            'dia_codigo' => $horario['dia_codigo'],
                            'dia_nombre' => $validacion['dia_nombre'],
                            'motivo' => $validacion['motivo'],
                            'horario_solicitado' => [
                                'entrada' => $horario['hora_entrada'],
                                'salida' => $horario['hora_salida']
                            ],
                            'horario_oficina' => $validacion['horario_oficina']
                        ];
                    }
                }

                if (!empty($horariosInvalidos)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Algunos horarios están fuera del rango de la oficina',
                        'data' => [
                            'horarios_invalidos' => $horariosInvalidos,
                            'total_invalidos' => count($horariosInvalidos),
                            'puede_forzar' => true
                        ]
                    ], 422);
                }
            }

            DB::beginTransaction();

            $sobrescribirExistentes = $request->get('sobrescribir_existentes', true);
            $resultados = [
                'creados' => 0,
                'actualizados' => 0,
                'saltados' => 0,
                'errores' => []
            ];

            foreach ($request->horarios as $horario) {
                try {
                    // Verificar si ya existe
                    $horarioExistente = DB::table('gaf_jorusu')
                        ->where('jorusu_usu_id', $usuarioId)
                        ->where('jorusu_diasem_codigo', $horario['dia_codigo'])
                        ->first();

                    if ($horarioExistente && !$sobrescribirExistentes) {
                        $resultados['saltados']++;
                        continue;
                    }

                    $horarioData = [
                        'jorusu_usu_id' => $usuarioId,
                        'jorusu_diasem_codigo' => $horario['dia_codigo'],
                        'jorusu_horentrada' => $horario['hora_entrada'],
                        'jorusu_horsalida' => $horario['hora_salida']
                    ];

                    if ($horarioExistente) {
                        DB::table('gaf_jorusu')
                            ->where('jorusu_usu_id', $usuarioId)
                            ->where('jorusu_diasem_codigo', $horario['dia_codigo'])
                            ->update($horarioData);
                        $resultados['actualizados']++;
                    } else {
                        DB::table('gaf_jorusu')->insert($horarioData);
                        $resultados['creados']++;
                    }

                } catch (\Exception $e) {
                    $resultados['errores'][] = [
                        'dia_codigo' => $horario['dia_codigo'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            $totalProcesados = $resultados['creados'] + $resultados['actualizados'];
            
            Log::info("✅ Horarios batch procesados para usuario:", [
                'usuario_id' => $usuarioId,
                'creados' => $resultados['creados'],
                'actualizados' => $resultados['actualizados'],
                'saltados' => $resultados['saltados'],
                'errores' => count($resultados['errores']),
                'validacion_oficina' => $validarContraOficina
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se procesaron {$totalProcesados} horarios correctamente",
                'data' => [
                    'usuario_id' => $usuarioId,
                    'resultados' => $resultados,
                    'total_procesados' => $totalProcesados,
                    'validacion_aplicada' => $validarContraOficina,
                    'forzado' => $forzarCreacion
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error en storeBatch horarios usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar horarios múltiples: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Clonar horarios desde la oficina del usuario
     * POST /api/usuarios/{usuarioId}/horarios/clonar-oficina
     */
    public function clonarDesdeOficina($usuarioId, Request $request)
    {
        try {
            Log::info("📋 Clonando horarios de oficina para usuario {$usuarioId}");
            
            $validator = Validator::make($request->all(), [
                'sobrescribir_existentes' => 'boolean',
                'solo_dias_activos' => 'boolean',
                'ajuste_minutos_entrada' => 'nullable|integer|between:-120,120',
                'ajuste_minutos_salida' => 'nullable|integer|between:-120,120'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Verificar que el usuario existe y tiene oficina
            $usuario = DB::table('tbl_usu')
                ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->where('tbl_usu.usu_id', $usuarioId)
                ->select('tbl_usu.*', 'gaf_oficin.oficin_nombre')
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            if (!$usuario->oficin_codigo) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El usuario no tiene oficina asignada',
                    'data' => null
                ], 422);
            }

            // Obtener horarios de la oficina
            $query = DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $usuario->oficin_codigo);

            if ($request->get('solo_dias_activos', true)) {
                $query->where('jorofi_ctrhabil', 1);
            }

            $horariosOficina = $query->get();

            if ($horariosOficina->isEmpty()) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'La oficina no tiene horarios configurados',
                    'data' => [
                        'usuario_id' => $usuarioId,
                        'oficina_id' => $usuario->oficin_codigo,
                        'horarios_clonados' => 0
                    ]
                ]);
            }

            $sobrescribirExistentes = $request->get('sobrescribir_existentes', true);
            $ajusteEntrada = $request->get('ajuste_minutos_entrada', 0);
            $ajusteSalida = $request->get('ajuste_minutos_salida', 0);

            DB::beginTransaction();

            $resultados = [
                'clonados' => 0,
                'saltados' => 0,
                'actualizados' => 0,
                'errores' => []
            ];

            foreach ($horariosOficina as $horarioOficina) {
                try {
                    // Verificar si ya existe horario personalizado para este día
                    $horarioExistente = DB::table('gaf_jorusu')
                        ->where('jorusu_usu_id', $usuarioId)
                        ->where('jorusu_diasem_codigo', $horarioOficina->jorofi_diasem_codigo)
                        ->first();

                    if ($horarioExistente && !$sobrescribirExistentes) {
                        $resultados['saltados']++;
                        continue;
                    }

                    // Aplicar ajustes de tiempo
                    $horaEntrada = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horinicial);
                    $horaSalida = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horfinal);

                    if ($ajusteEntrada !== 0) {
                        $horaEntrada->addMinutes($ajusteEntrada);
                    }
                    if ($ajusteSalida !== 0) {
                        $horaSalida->addMinutes($ajusteSalida);
                    }

                    $nuevoHorario = [
                        'jorusu_usu_id' => $usuarioId,
                        'jorusu_diasem_codigo' => $horarioOficina->jorofi_diasem_codigo,
                        'jorusu_horentrada' => $horaEntrada->format('H:i'),
                        'jorusu_horsalida' => $horaSalida->format('H:i')
                    ];

                    if ($horarioExistente) {
                        DB::table('gaf_jorusu')
                            ->where('jorusu_usu_id', $usuarioId)
                            ->where('jorusu_diasem_codigo', $horarioOficina->jorofi_diasem_codigo)
                            ->update($nuevoHorario);
                        $resultados['actualizados']++;
                    } else {
                        DB::table('gaf_jorusu')->insert($nuevoHorario);
                        $resultados['clonados']++;
                    }

                } catch (\Exception $e) {
                    $resultados['errores'][] = [
                        'dia_codigo' => $horarioOficina->jorofi_diasem_codigo,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            $totalProcesados = $resultados['clonados'] + $resultados['actualizados'];

            Log::info("✅ Horarios clonados desde oficina exitosamente:", [
                'usuario_id' => $usuarioId,
                'oficina_id' => $usuario->oficin_codigo,
                'clonados' => $resultados['clonados'],
                'actualizados' => $resultados['actualizados'],
                'saltados' => $resultados['saltados']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se clonaron {$totalProcesados} horarios desde la oficina",
                'data' => [
                    'usuario_id' => $usuarioId,
                    'oficina' => [
                        'codigo' => $usuario->oficin_codigo,
                        'nombre' => $usuario->oficin_nombre
                    ],
                    'resultados' => $resultados,
                    'total_procesados' => $totalProcesados,
                    'ajustes_aplicados' => [
                        'entrada_minutos' => $ajusteEntrada,
                        'salida_minutos' => $ajusteSalida
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error clonando horarios desde oficina: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al clonar horarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Validar si un usuario puede acceder en un momento específico
     * GET /api/usuarios/{usuarioId}/validar-acceso?fecha=YYYY-MM-DD&hora=HH:MM
     */
    public function validarAcceso($usuarioId, Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha' => 'required|date_format:Y-m-d',
                'hora' => 'required|date_format:H:i'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $fecha = Carbon::parse($request->fecha);
            $hora = $request->hora;
            $diaSemana = $fecha->dayOfWeekIso; // 1=Lunes, 7=Domingo

            // Verificar que el usuario existe y está activo
            $usuario = DB::table('tbl_usu')
                ->leftJoin('tbl_est', 'tbl_usu.est_id', '=', 'tbl_est.est_id')
                ->where('tbl_usu.usu_id', $usuarioId)
                ->select('tbl_usu.*', 'tbl_est.est_nom')
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => [
                        'puede_acceder' => false,
                        'motivo' => 'USUARIO_NO_ENCONTRADO'
                    ]
                ], 404);
            }

            if ($usuario->est_id != 1) { // Asumiendo que 1 = Activo
                return response()->json([
                    'status' => 'success',
                    'message' => 'Usuario inactivo',
                    'data' => [
                        'puede_acceder' => false,
                        'motivo' => 'USUARIO_INACTIVO',
                        'estado_usuario' => $usuario->est_nom
                    ]
                ]);
            }

            // Buscar horario personalizado del usuario
            $horarioPersonalizado = DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioId)
                ->where('jorusu_diasem_codigo', $diaSemana)
                ->first();

            $horarioEfectivo = null;
            $origenHorario = '';

            if ($horarioPersonalizado) {
                // Usar horario personalizado
                $horarioEfectivo = [
                    'hora_entrada' => $horarioPersonalizado->jorusu_horentrada,
                    'hora_salida' => $horarioPersonalizado->jorusu_horsalida
                ];
                $origenHorario = 'PERSONALIZADO';
            } elseif ($usuario->oficin_codigo) {
                // Buscar horario de oficina
                $horarioOficina = DB::table('gaf_jorofi')
                    ->where('jorofi_oficin_codigo', $usuario->oficin_codigo)
                    ->where('jorofi_diasem_codigo', $diaSemana)
                    ->where('jorofi_ctrhabil', 1)
                    ->first();

                if ($horarioOficina) {
                    $horarioEfectivo = [
                        'hora_entrada' => $horarioOficina->jorofi_horinicial,
                        'hora_salida' => $horarioOficina->jorofi_horfinal
                    ];
                    $origenHorario = 'HEREDADO_OFICINA';
                }
            }

            if (!$horarioEfectivo) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Sin horario configurado para este día',
                    'data' => [
                        'puede_acceder' => false,
                        'motivo' => 'SIN_HORARIO',
                        'dia_semana' => $diaSemana,
                        'fecha_consultada' => $request->fecha,
                        'hora_consultada' => $hora,
                        'origen_horario' => 'NINGUNO'
                    ]
                ]);
            }

            // Validar si la hora está dentro del rango permitido
            $horaConsulta = Carbon::createFromFormat('H:i', $hora);
            $horaEntrada = Carbon::createFromFormat('H:i', $horarioEfectivo['hora_entrada']);
            $horaSalida = Carbon::createFromFormat('H:i', $horarioEfectivo['hora_salida']);

            $puedeAcceder = false;
            $dentroDelHorario = false;

            // Verificar si el horario cruza medianoche
            if ($horaSalida < $horaEntrada) {
                // Horario nocturno que cruza medianoche (ej: 22:00 - 06:00)
                $dentroDelHorario = $horaConsulta >= $horaEntrada || $horaConsulta <= $horaSalida;
            } else {
                // Horario normal (ej: 08:00 - 18:00)
                $dentroDelHorario = $horaConsulta >= $horaEntrada && $horaConsulta <= $horaSalida;
            }

            $puedeAcceder = $dentroDelHorario;

            return response()->json([
                'status' => 'success',
                'message' => $puedeAcceder ? 'Acceso permitido' : 'Acceso denegado - fuera de horario',
                'data' => [
                    'puede_acceder' => $puedeAcceder,
                    'motivo' => $puedeAcceder ? 'DENTRO_DE_HORARIO' : 'FUERA_DE_HORARIO',
                    'usuario_id' => $usuarioId,
                    'fecha_consultada' => $request->fecha,
                    'hora_consultada' => $hora,
                    'dia_semana' => $diaSemana,
                    'origen_horario' => $origenHorario,
                    'horario_efectivo' => [
                        'hora_entrada' => $horarioEfectivo['hora_entrada'],
                        'hora_salida' => $horarioEfectivo['hora_salida'],
                        'cruza_medianoche' => $horaSalida < $horaEntrada,
                        'jornada' => $horaEntrada->hour < 12 ? 'MATUTINA' : 'NOCTURNA'
                    ],
                    'oficina_codigo' => $usuario->oficin_codigo
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error validando acceso usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al validar acceso: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener horario actual efectivo del usuario
     * GET /api/usuarios/{usuarioId}/horario-actual
     */
    public function horarioActual($usuarioId)
    {
        try {
            $now = Carbon::now();
            $diaSemana = $now->dayOfWeekIso; // 1=Lunes, 7=Domingo
            
            // Verificar que el usuario existe
            $usuario = DB::table('tbl_usu')
                ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->where('tbl_usu.usu_id', $usuarioId)
                ->select('tbl_usu.*', 'gaf_oficin.oficin_nombre', 'gaf_oficin.oficin_ctractual')
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // Buscar horario personalizado
            $horarioPersonalizado = DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioId)
                ->where('jorusu_diasem_codigo', $diaSemana)
                ->first();

            // Buscar horario de oficina como respaldo
            $horarioOficina = null;
            if ($usuario->oficin_codigo) {
                $horarioOficina = DB::table('gaf_jorofi')
                    ->where('jorofi_oficin_codigo', $usuario->oficin_codigo)
                    ->where('jorofi_diasem_codigo', $diaSemana)
                    ->where('jorofi_ctrhabil', 1)
                    ->first();
            }

            $response = [
                'usuario_id' => $usuarioId,
                'fecha_actual' => $now->format('Y-m-d'),
                'hora_actual' => $now->format('H:i'),
                'dia_semana' => $diaSemana,
                'tiene_horario_personalizado' => $horarioPersonalizado ? true : false,
                'tiene_horario_oficina' => $horarioOficina ? true : false,
                'usuario_activo' => $usuario->est_id == 1,
                'oficina_operativa' => $usuario->oficin_ctractual == 1
            ];

            // Determinar horario efectivo
            $horarioEfectivo = null;
            $origenHorario = 'NINGUNO';

            if ($horarioPersonalizado) {
                $horarioEfectivo = [
                    'hora_entrada' => $horarioPersonalizado->jorusu_horentrada,
                    'hora_salida' => $horarioPersonalizado->jorusu_horsalida
                ];
                $origenHorario = 'PERSONALIZADO';
            } elseif ($horarioOficina) {
                $horarioEfectivo = [
                    'hora_entrada' => $horarioOficina->jorofi_horinicial,
                    'hora_salida' => $horarioOficina->jorofi_horfinal
                ];
                $origenHorario = 'HEREDADO_OFICINA';
            }

            $response['origen_horario'] = $origenHorario;

            if ($horarioEfectivo) {
                $horaEntrada = Carbon::createFromFormat('H:i', $horarioEfectivo['hora_entrada']);
                $horaSalida = Carbon::createFromFormat('H:i', $horarioEfectivo['hora_salida']);
                $horaActual = Carbon::createFromFormat('H:i', $now->format('H:i'));
                
                // Verificar si está dentro del horario
                $dentroDelHorario = false;
                if ($horaSalida < $horaEntrada) {
                    // Horario nocturno que cruza medianoche
                    $dentroDelHorario = $horaActual >= $horaEntrada || $horaActual <= $horaSalida;
                } else {
                    // Horario normal
                    $dentroDelHorario = $horaActual >= $horaEntrada && $horaActual <= $horaSalida;
                }

                // Calcular tiempo restante hasta la salida
                $tiempoRestante = null;
                if ($dentroDelHorario) {
                    if ($horaSalida < $horaEntrada) {
                        // Horario nocturno
                        if ($horaActual >= $horaEntrada) {
                            $tiempoRestante = $horaActual->diffInMinutes($horaSalida->addDay());
                        } else {
                            $tiempoRestante = $horaActual->diffInMinutes($horaSalida);
                        }
                    } else {
                        // Horario normal
                        $tiempoRestante = $horaActual->diffInMinutes($horaSalida);
                    }
                }

                $response['horario_detalle'] = [
                    'hora_entrada' => $horarioEfectivo['hora_entrada'],
                    'hora_salida' => $horarioEfectivo['hora_salida'],
                    'cruza_medianoche' => $horaSalida < $horaEntrada,
                    'jornada' => $horaEntrada->hour < 12 ? 'MATUTINA' : 'NOCTURNA',
                    'dentro_de_horario' => $dentroDelHorario,
                    'tiempo_restante_minutos' => $tiempoRestante,
                    'alerta_salida_proxima' => $tiempoRestante && $tiempoRestante <= 30 // 30 minutos
                ];

                $response['puede_acceder'] = $response['usuario_activo'] && 
                                           $response['oficina_operativa'] && 
                                           $dentroDelHorario;
            } else {
                $response['horario_detalle'] = null;
                $response['puede_acceder'] = false;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Horario actual obtenido correctamente',
                'data' => $response
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo horario actual usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener horario actual: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener horarios de mi usuario autenticado
     * GET /api/usuarios/me/horarios
     */
    public function miHorario()
    {
        try {
            $userId = Auth::id();
            
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado',
                    'data' => null
                ], 401);
            }

            // Usar el método index existente
            return $this->index($userId, request());
            
        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo mi horario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener mi horario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener mi horario actual
     * GET /api/usuarios/me/horario-actual
     */
    public function miHorarioActual()
    {
        try {
            $userId = Auth::id();
            
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado',
                    'data' => null
                ], 401);
            }

            // Usar el método horarioActual existente
            return $this->horarioActual($userId);
            
        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo mi horario actual: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener mi horario actual: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
    /**
     * Eliminar horario personalizado de un día específico
     * DELETE /api/usuarios/{usuarioId}/horarios/{diaId}
     */
    public function destroy($usuarioId, $diaId)
    {
        try {
            Log::info("🗑️ Eliminando horario personalizado usuario {$usuarioId}, día {$diaId}");
            
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

            // Verificar que el día existe
            $dia = DB::table('gaf_diasem')
                ->where('diasem_codigo', $diaId)
                ->first();

            if (!$dia) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Día de la semana no válido',
                    'data' => null
                ], 404);
            }

            // Verificar que existe el horario a eliminar
            $horario = DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioId)
                ->where('jorusu_diasem_codigo', $diaId)
                ->first();

            if (!$horario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No existe horario personalizado para este día',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioId)
                ->where('jorusu_diasem_codigo', $diaId)
                ->delete();

            $horarioEliminado = [
                'usuario_id' => $usuarioId,
                'dia_codigo' => $diaId,
                'dia_nombre' => trim($dia->diasem_nombre),
                'horario_eliminado' => $horario->jorusu_horentrada . ' - ' . $horario->jorusu_horsalida
            ];

            DB::commit();

            Log::info("✅ Horario personalizado eliminado exitosamente:", $horarioEliminado);

            return response()->json([
                'status' => 'success',
                'message' => 'Horario personalizado eliminado para ' . trim($dia->diasem_nombre) . '. El usuario volverá a usar el horario de oficina.',
                'data' => $horarioEliminado
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error eliminando horario personalizado: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar horario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Eliminar todos los horarios personalizados de un usuario
     * DELETE /api/usuarios/{usuarioId}/horarios
     */
    public function destroyAll($usuarioId)
    {
        try {
            Log::info("🗑️ Eliminando todos los horarios personalizados de usuario {$usuarioId}");
            
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

            // Contar horarios existentes
            $cantidadHorarios = DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioId)
                ->count();

            if ($cantidadHorarios === 0) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'No hay horarios personalizados para eliminar',
                    'data' => [
                        'usuario_id' => $usuarioId,
                        'horarios_eliminados' => 0
                    ]
                ]);
            }

            DB::beginTransaction();

            DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioId)
                ->delete();

            DB::commit();

            Log::info("✅ Todos los horarios personalizados eliminados exitosamente:", [
                'usuario_id' => $usuarioId,
                'cantidad_eliminada' => $cantidadHorarios
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se eliminaron {$cantidadHorarios} horarios personalizados. El usuario volverá a usar los horarios de oficina.",
                'data' => [
                    'usuario_id' => $usuarioId,
                    'horarios_eliminados' => $cantidadHorarios
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error eliminando todos los horarios personalizados: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar horarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de horarios personalizados
     * GET /api/horarios/usuarios/estadisticas
     */
    public function estadisticasUsuarios(Request $request)
    {
        try {
            Log::info("📊 Obteniendo estadísticas de horarios de usuarios");
            
            $fechaInicio = $request->get('fecha_inicio', Carbon::now()->subMonth()->format('Y-m-d'));
            $fechaFin = $request->get('fecha_fin', Carbon::now()->format('Y-m-d'));

            $stats = [
                'usuarios_totales' => DB::table('tbl_usu')->where('est_id', 1)->count(),
                'usuarios_con_horarios_personalizados' => DB::table('tbl_usu')
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('gaf_jorusu')
                            ->whereRaw('gaf_jorusu.jorusu_usu_id = tbl_usu.usu_id');
                    })
                    ->where('est_id', 1)
                    ->count(),
                'usuarios_solo_horario_oficina' => DB::table('tbl_usu')
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('gaf_jorusu')
                            ->whereRaw('gaf_jorusu.jorusu_usu_id = tbl_usu.usu_id');
                    })
                    ->whereNotNull('oficin_codigo')
                    ->where('est_id', 1)
                    ->count(),
                'total_horarios_personalizados' => DB::table('gaf_jorusu')->count(),
                'usuarios_sin_oficina' => DB::table('tbl_usu')
                    ->whereNull('oficin_codigo')
                    ->where('est_id', 1)
                    ->count()
            ];

            // Distribución por días de la semana
            $distribucionDias = DB::table('gaf_jorusu')
                ->join('gaf_diasem', 'gaf_jorusu.jorusu_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->join('tbl_usu', 'gaf_jorusu.jorusu_usu_id', '=', 'tbl_usu.usu_id')
                ->where('tbl_usu.est_id', 1)
                ->select(
                    'gaf_diasem.diasem_nombre',
                    'gaf_diasem.diasem_codigo',
                    DB::raw('COUNT(*) as total_usuarios'),
                    DB::raw('AVG(EXTRACT(HOUR FROM gaf_jorusu.jorusu_horentrada)) as hora_entrada_promedio'),
                    DB::raw('AVG(EXTRACT(HOUR FROM gaf_jorusu.jorusu_horsalida)) as hora_salida_promedio')
                )
                ->groupBy('gaf_diasem.diasem_codigo', 'gaf_diasem.diasem_nombre')
                ->orderBy('gaf_diasem.diasem_codigo')
                ->get();

            // Top usuarios con más días personalizados
            $topUsuarios = DB::table('tbl_usu')
                ->leftJoin('gaf_jorusu', 'tbl_usu.usu_id', '=', 'gaf_jorusu.jorusu_usu_id')
                ->leftJoin('gaf_oficin', 'tbl_usu.oficin_codigo', '=', 'gaf_oficin.oficin_codigo')
                ->leftJoin('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->where('tbl_usu.est_id', 1)
                ->select(
                    'tbl_usu.usu_id',
                    'tbl_usu.usu_nom',
                    'tbl_usu.usu_ape',
                    'tbl_usu.usu_cor',
                    'gaf_oficin.oficin_nombre',
                    'tbl_per.per_nom',
                    DB::raw("CONCAT(COALESCE(tbl_usu.usu_nom, ''), ' ', COALESCE(tbl_usu.usu_ape, '')) as nombre_completo"),
                    DB::raw('COUNT(gaf_jorusu.jorusu_diasem_codigo) as dias_personalizados')
                )
                ->groupBy('tbl_usu.usu_id', 'tbl_usu.usu_nom', 'tbl_usu.usu_ape', 'tbl_usu.usu_cor', 
                         'gaf_oficin.oficin_nombre', 'tbl_per.per_nom')
                ->orderBy('dias_personalizados', 'desc')
                ->limit(10)
                ->get();

            // Estadísticas por oficina
            $estadisticasPorOficina = DB::table('gaf_oficin')
                ->leftJoin('tbl_usu', 'gaf_oficin.oficin_codigo', '=', 'tbl_usu.oficin_codigo')
                ->leftJoin('gaf_jorusu', 'tbl_usu.usu_id', '=', 'gaf_jorusu.jorusu_usu_id')
                ->where('gaf_oficin.oficin_ctractual', 1)
                ->where('tbl_usu.est_id', 1)
                ->select(
                    'gaf_oficin.oficin_codigo',
                    'gaf_oficin.oficin_nombre',
                    DB::raw('COUNT(DISTINCT tbl_usu.usu_id) as total_usuarios'),
                    DB::raw('COUNT(DISTINCT CASE WHEN gaf_jorusu.jorusu_usu_id IS NOT NULL THEN tbl_usu.usu_id END) as usuarios_con_horarios_personalizados'),
                    DB::raw('COUNT(gaf_jorusu.jorusu_diasem_codigo) as total_horarios_personalizados')
                )
                ->groupBy('gaf_oficin.oficin_codigo', 'gaf_oficin.oficin_nombre')
                ->orderBy('usuarios_con_horarios_personalizados', 'desc')
                ->limit(10)
                ->get();

            Log::info("✅ Estadísticas de horarios de usuarios obtenidas correctamente:", [
                'usuarios_totales' => $stats['usuarios_totales'],
                'usuarios_con_horarios_personalizados' => $stats['usuarios_con_horarios_personalizados']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Estadísticas de horarios obtenidas correctamente',
                'data' => [
                    'resumen_general' => $stats,
                    'distribucion_por_dias' => $distribucionDias,
                    'top_usuarios_personalizados' => $topUsuarios,
                    'estadisticas_por_oficina' => $estadisticasPorOficina,
                    'periodo_consultado' => [
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo estadísticas de horarios: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Transferir usuario a nueva oficina y reasignar horarios
     * POST /api/usuarios/{usuarioId}/transferir-oficina
     */
    public function transferirOficina($usuarioId, Request $request)
    {
        try {
            Log::info("🏢 Transfiriendo usuario {$usuarioId} a nueva oficina");
            
            $validator = Validator::make($request->all(), [
                'nueva_oficina_codigo' => 'required|string|exists:gaf_oficin,oficin_codigo',
                'mantener_horarios_personalizados' => 'boolean',
                'fecha_efectiva' => 'nullable|date_format:Y-m-d|after_or_equal:today',
                'motivo_transferencia' => 'nullable|string|max:500'
            ], [
                'nueva_oficina_codigo.required' => 'El código de la nueva oficina es requerido',
                'nueva_oficina_codigo.exists' => 'La oficina especificada no existe',
                'fecha_efectiva.after_or_equal' => 'La fecha efectiva debe ser hoy o posterior'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Verificar que el usuario existe
            $usuario = DB::table('tbl_usu')
                ->leftJoin('gaf_oficin as oficina_actual', 'tbl_usu.oficin_codigo', '=', 'oficina_actual.oficin_codigo')
                ->where('tbl_usu.usu_id', $usuarioId)
                ->select('tbl_usu.*', 'oficina_actual.oficin_nombre as oficina_actual_nombre')
                ->first();

            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // Verificar que la nueva oficina existe y está activa
            $nuevaOficina = DB::table('gaf_oficin')
                ->where('oficin_codigo', $request->nueva_oficina_codigo)
                ->where('oficin_ctractual', 1)
                ->first();

            if (!$nuevaOficina) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La nueva oficina no existe o no está activa',
                    'data' => null
                ], 422);
            }

            // Verificar que no es la misma oficina
            if ($usuario->oficin_codigo === $request->nueva_oficina_codigo) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'El usuario ya pertenece a esta oficina',
                    'data' => [
                        'usuario_id' => $usuarioId,
                        'oficina_actual' => $usuario->oficin_codigo,
                        'oficina_solicitada' => $request->nueva_oficina_codigo
                    ]
                ]);
            }

            DB::beginTransaction();

            // Guardar horarios personalizados actuales antes de la transferencia
            $horariosPersonalizadosActuales = DB::table('gaf_jorusu')
                ->leftJoin('gaf_diasem', 'gaf_jorusu.jorusu_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('jorusu_usu_id', $usuarioId)
                ->select(
                    'gaf_jorusu.*',
                    'gaf_diasem.diasem_nombre'
                )
                ->get();

            $mantenerHorarios = $request->get('mantener_horarios_personalizados', false);
            $horariosEliminados = [];
            $horariosIncompatibles = [];

            if (!$mantenerHorarios) {
                // Eliminar horarios personalizados existentes
                foreach ($horariosPersonalizadosActuales as $horario) {
                    $horariosEliminados[] = [
                        'dia_codigo' => $horario->jorusu_diasem_codigo,
                        'dia_nombre' => trim($horario->diasem_nombre),
                        'hora_entrada' => $horario->jorusu_horentrada,
                        'hora_salida' => $horario->jorusu_horsalida
                    ];
                }

                DB::table('gaf_jorusu')
                    ->where('jorusu_usu_id', $usuarioId)
                    ->delete();
            } else {
                // Validar horarios personalizados contra nueva oficina
                $horariosNuevaOficina = DB::table('gaf_jorofi')
                    ->where('jorofi_oficin_codigo', $request->nueva_oficina_codigo)
                    ->where('jorofi_ctrhabil', 1)
                    ->get()
                    ->keyBy('jorofi_diasem_codigo');
                
                foreach ($horariosPersonalizadosActuales as $horarioPersonal) {
                    $horarioOficina = $horariosNuevaOficina->get($horarioPersonal->jorusu_diasem_codigo);
                    
                    if ($horarioOficina) {
                        $entradaPersonal = Carbon::createFromFormat('H:i', $horarioPersonal->jorusu_horentrada);
                        $salidaPersonal = Carbon::createFromFormat('H:i', $horarioPersonal->jorusu_horsalida);
                        $entradaOficina = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horinicial);
                        $salidaOficina = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horfinal);

                        if ($entradaPersonal < $entradaOficina || $salidaPersonal > $salidaOficina) {
                            $horariosIncompatibles[] = [
                                'dia_codigo' => $horarioPersonal->jorusu_diasem_codigo,
                                'dia_nombre' => trim($horarioPersonal->diasem_nombre),
                                'horario_personal' => $horarioPersonal->jorusu_horentrada . ' - ' . $horarioPersonal->jorusu_horsalida,
                                'horario_oficina' => $horarioOficina->jorofi_horinicial . ' - ' . $horarioOficina->jorofi_horfinal
                            ];

                            // Eliminar horario incompatible
                            DB::table('gaf_jorusu')
                                ->where('jorusu_usu_id', $usuarioId)
                                ->where('jorusu_diasem_codigo', $horarioPersonal->jorusu_diasem_codigo)
                                ->delete();
                        }
                    }
                }
            }

            // Actualizar oficina del usuario
            DB::table('tbl_usu')
                ->where('usu_id', $usuarioId)
                ->update([
                    'oficin_codigo' => $request->nueva_oficina_codigo
                ]);

            // Datos de la transferencia
            $transferencia = [
                'usuario_id' => $usuarioId,
                'oficina_anterior' => $usuario->oficin_codigo,
                'oficina_anterior_nombre' => $usuario->oficina_actual_nombre,
                'oficina_nueva' => $request->nueva_oficina_codigo,
                'oficina_nueva_nombre' => $nuevaOficina->oficin_nombre,
                'horarios_personalizados_anteriores' => count($horariosPersonalizadosActuales),
                'horarios_eliminados' => count($horariosEliminados),
                'horarios_incompatibles' => $horariosIncompatibles,
                'mantener_horarios' => $mantenerHorarios,
                'fecha_efectiva' => $request->get('fecha_efectiva', Carbon::now()->format('Y-m-d')),
                'motivo' => $request->motivo_transferencia
            ];

            DB::commit();

            Log::info("✅ Usuario transferido exitosamente:", $transferencia);

            return response()->json([
                'status' => 'success',
                'message' => "Usuario transferido exitosamente de {$usuario->oficina_actual_nombre} a {$nuevaOficina->oficin_nombre}",
                'data' => $transferencia
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error transfiriendo usuario a nueva oficina: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al transferir usuario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Copiar horarios de un usuario a otro
     * POST /api/usuarios/{usuarioOrigenId}/horarios/copiar/{usuarioDestinoId}
     */
    public function copiarHorarios($usuarioOrigenId, $usuarioDestinoId, Request $request)
    {
        try {
            Log::info("📋 Copiando horarios de usuario {$usuarioOrigenId} a {$usuarioDestinoId}");
            
            $validator = Validator::make($request->all(), [
                'sobrescribir_existentes' => 'boolean',
                'validar_contra_oficina_destino' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Verificar que ambos usuarios existen
            $usuarioOrigen = DB::table('tbl_usu')->where('usu_id', $usuarioOrigenId)->first();
            $usuarioDestino = DB::table('tbl_usu')->where('usu_id', $usuarioDestinoId)->first();

            if (!$usuarioOrigen) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario origen no encontrado',
                    'data' => null
                ], 404);
            }

            if (!$usuarioDestino) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario destino no encontrado',
                    'data' => null
                ], 404);
            }

            if ($usuarioOrigenId == $usuarioDestinoId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede copiar horarios al mismo usuario',
                    'data' => null
                ], 422);
            }

            // Obtener horarios del usuario origen
            $horariosOrigen = DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioOrigenId)
                ->get();

            if ($horariosOrigen->isEmpty()) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'El usuario origen no tiene horarios personalizados',
                    'data' => [
                        'usuario_origen' => $usuarioOrigenId,
                        'usuario_destino' => $usuarioDestinoId,
                        'horarios_copiados' => 0
                    ]
                ]);
            }

            // Validar contra oficina destino si se requiere
            $horariosOficinaDestino = [];
            if ($request->get('validar_contra_oficina_destino', true) && $usuarioDestino->oficin_codigo) {
                $horariosOficinaDestino = DB::table('gaf_jorofi')
                    ->where('jorofi_oficin_codigo', $usuarioDestino->oficin_codigo)
                    ->where('jorofi_ctrhabil', 1)
                    ->get()
                    ->keyBy('jorofi_diasem_codigo');
            }

            DB::beginTransaction();

            $sobrescribirExistentes = $request->get('sobrescribir_existentes', true);
            $resultados = [
                'copiados' => 0,
                'saltados' => 0,
                'actualizados' => 0,
                'errores' => []
            ];

            foreach ($horariosOrigen as $horario) {
                try {
                    // Validar contra oficina destino
                    if (isset($horariosOficinaDestino[$horario->jorusu_diasem_codigo])) {
                        $horarioOficina = $horariosOficinaDestino[$horario->jorusu_diasem_codigo];
                        $entradaUsuario = Carbon::createFromFormat('H:i', $horario->jorusu_horentrada);
                        $salidaUsuario = Carbon::createFromFormat('H:i', $horario->jorusu_horsalida);
                        $entradaOficina = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horinicial);
                        $salidaOficina = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horfinal);

                        if ($entradaUsuario < $entradaOficina || $salidaUsuario > $salidaOficina) {
                            $resultados['errores'][] = [
                                'dia_codigo' => $horario->jorusu_diasem_codigo,
                                'error' => 'Horario fuera del rango de la oficina destino'
                            ];
                            continue;
                        }
                    }

                    // Verificar si ya existe horario para este día en destino
                    $horarioExistente = DB::table('gaf_jorusu')
                        ->where('jorusu_usu_id', $usuarioDestinoId)
                        ->where('jorusu_diasem_codigo', $horario->jorusu_diasem_codigo)
                        ->first();

                    if ($horarioExistente && !$sobrescribirExistentes) {
                        $resultados['saltados']++;
                        continue;
                    }

                    $nuevoHorario = [
                        'jorusu_usu_id' => $usuarioDestinoId,
                        'jorusu_diasem_codigo' => $horario->jorusu_diasem_codigo,
                        'jorusu_horentrada' => $horario->jorusu_horentrada,
                        'jorusu_horsalida' => $horario->jorusu_horsalida
                    ];

                    if ($horarioExistente) {
                        DB::table('gaf_jorusu')
                            ->where('jorusu_usu_id', $usuarioDestinoId)
                            ->where('jorusu_diasem_codigo', $horario->jorusu_diasem_codigo)
                            ->update($nuevoHorario);
                        $resultados['actualizados']++;
                    } else {
                        DB::table('gaf_jorusu')->insert($nuevoHorario);
                        $resultados['copiados']++;
                    }

                } catch (\Exception $e) {
                    $resultados['errores'][] = [
                        'dia_codigo' => $horario->jorusu_diasem_codigo,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            $totalProcesados = $resultados['copiados'] + $resultados['actualizados'];

            Log::info("✅ Horarios copiados exitosamente:", [
                'origen' => $usuarioOrigenId,
                'destino' => $usuarioDestinoId,
                'copiados' => $resultados['copiados'],
                'actualizados' => $resultados['actualizados'],
                'saltados' => $resultados['saltados']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se procesaron {$totalProcesados} horarios correctamente",
                'data' => [
                    'usuario_origen' => $usuarioOrigenId,
                    'usuario_destino' => $usuarioDestinoId,
                    'resultados' => $resultados,
                    'total_procesados' => $totalProcesados
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error copiando horarios: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al copiar horarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
/**
     * Crear horario temporal para un usuario
     * POST /api/usuarios/{usuarioId}/horarios/temporal
     */
    public function storeHorarioTemporal($usuarioId, Request $request)
    {
        try {
            Log::info("⏰ Creando horario temporal para usuario {$usuarioId}");
            
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|date_format:Y-m-d|after_or_equal:today',
                'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
                'horarios' => 'required|array|min:1',
                'horarios.*.dia_codigo' => 'required|integer|between:1,7|exists:gaf_diasem,diasem_codigo',
                'horarios.*.hora_entrada' => 'required|date_format:H:i',
                'horarios.*.hora_salida' => 'required|date_format:H:i|after:horarios.*.hora_entrada',
                'motivo' => 'required|string|max:200',
                'tipo_temporal' => 'required|string|in:VACACIONES,PERMISO_MEDICO,PROYECTO_ESPECIAL,CAPACITACION,OTRO'
            ], [
                'fecha_inicio.after_or_equal' => 'La fecha de inicio debe ser hoy o posterior',
                'fecha_fin.after_or_equal' => 'La fecha fin debe ser posterior o igual a la fecha inicio',
                'motivo.required' => 'El motivo del horario temporal es requerido',
                'tipo_temporal.required' => 'El tipo de horario temporal es requerido'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Verificar que el usuario existe
            $usuario = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            // Validar que no hay solapamiento con otros temporales
            $fechaInicio = Carbon::parse($request->fecha_inicio);
            $fechaFin = Carbon::parse($request->fecha_fin);

            $conflictos = DB::table('gaf_jorusu_temp')
                ->where('temp_usu_id', $usuarioId)
                ->where('temp_activo', true)
                ->where(function ($query) use ($fechaInicio, $fechaFin) {
                    $query->whereBetween('temp_fecha_inicio', [$fechaInicio, $fechaFin])
                          ->orWhereBetween('temp_fecha_fin', [$fechaInicio, $fechaFin])
                          ->orWhere(function ($q) use ($fechaInicio, $fechaFin) {
                              $q->where('temp_fecha_inicio', '<=', $fechaInicio)
                                ->where('temp_fecha_fin', '>=', $fechaFin);
                          });
                })
                ->exists();

            if ($conflictos) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ya existe un horario temporal que se solapa con las fechas especificadas',
                    'data' => null
                ], 422);
            }

            // Validar horarios contra oficina si es necesario
            if ($usuario->oficin_codigo) {
                $horariosOficina = DB::table('gaf_jorofi')
                    ->where('jorofi_oficin_codigo', $usuario->oficin_codigo)
                    ->where('jorofi_ctrhabil', 1)
                    ->get()
                    ->keyBy('jorofi_diasem_codigo');

                foreach ($request->horarios as $horario) {
                    if (isset($horariosOficina[$horario['dia_codigo']])) {
                        $horarioOficina = $horariosOficina[$horario['dia_codigo']];
                        $entradaTemporal = Carbon::createFromFormat('H:i', $horario['hora_entrada']);
                        $salidaTemporal = Carbon::createFromFormat('H:i', $horario['hora_salida']);
                        $entradaOficina = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horinicial);
                        $salidaOficina = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horfinal);

                        if ($entradaTemporal < $entradaOficina || $salidaTemporal > $salidaOficina) {
                            return response()->json([
                                'status' => 'error',
                                'message' => 'El horario temporal debe estar dentro del rango de la oficina',
                                'data' => [
                                    'dia_codigo' => $horario['dia_codigo'],
                                    'horario_oficina' => $horarioOficina->jorofi_horinicial . ' - ' . $horarioOficina->jorofi_horfinal,
                                    'horario_solicitado' => $horario['hora_entrada'] . ' - ' . $horario['hora_salida']
                                ]
                            ], 422);
                        }
                    }
                }
            }

            DB::beginTransaction();

            // Insertar horarios temporales
            $horariosCreados = [];
            foreach ($request->horarios as $horario) {
                $horarioTemporal = [
                    'temp_usu_id' => $usuarioId,
                    'temp_diasem_codigo' => $horario['dia_codigo'],
                    'temp_fecha_inicio' => $request->fecha_inicio,
                    'temp_fecha_fin' => $request->fecha_fin,
                    'temp_horentrada' => $horario['hora_entrada'],
                    'temp_horsalida' => $horario['hora_salida'],
                    'temp_motivo' => $request->motivo,
                    'temp_tipo' => $request->tipo_temporal,
                    'temp_activo' => true,
                    'temp_created_at' => now()
                ];

                $tempId = DB::table('gaf_jorusu_temp')->insertGetId($horarioTemporal);
                $horariosCreados[] = array_merge($horarioTemporal, ['temp_id' => $tempId]);
            }

            DB::commit();

            $duracionDias = $fechaInicio->diffInDays($fechaFin) + 1;

            Log::info("✅ Horarios temporales creados exitosamente:", [
                'usuario_id' => $usuarioId,
                'tipo' => $request->tipo_temporal,
                'duracion_dias' => $duracionDias,
                'horarios_creados' => count($horariosCreados)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Horarios temporales creados para {$duracionDias} días",
                'data' => [
                    'usuario_id' => $usuarioId,
                    'periodo' => [
                        'fecha_inicio' => $request->fecha_inicio,
                        'fecha_fin' => $request->fecha_fin,
                        'duracion_dias' => $duracionDias
                    ],
                    'tipo_temporal' => $request->tipo_temporal,
                    'motivo' => $request->motivo,
                    'horarios_creados' => $horariosCreados
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error creando horarios temporales: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear horarios temporales: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener horarios temporales de un usuario
     * GET /api/usuarios/{usuarioId}/horarios/temporales
     */
    public function getHorariosTemporales($usuarioId, Request $request)
    {
        try {
            Log::info("📅 Obteniendo horarios temporales para usuario {$usuarioId}");

            $usuario = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            $incluirVencidos = $request->get('incluir_vencidos', false);
            $fechaInicio = $request->get('fecha_inicio');
            $fechaFin = $request->get('fecha_fin');

            $query = DB::table('gaf_jorusu_temp as temp')
                ->leftJoin('gaf_diasem as dia', 'temp.temp_diasem_codigo', '=', 'dia.diasem_codigo')
                ->where('temp.temp_usu_id', $usuarioId);

            if (!$incluirVencidos) {
                $query->where('temp.temp_activo', true)
                      ->where('temp.temp_fecha_fin', '>=', Carbon::today());
            }

            if ($fechaInicio) {
                $query->where('temp.temp_fecha_fin', '>=', $fechaInicio);
            }
            if ($fechaFin) {
                $query->where('temp.temp_fecha_inicio', '<=', $fechaFin);
            }

            $horariosTemporales = $query->select(
                'temp.*',
                'dia.diasem_nombre',
                'dia.diasem_abreviatura'
            )->orderBy('temp.temp_fecha_inicio', 'desc')
             ->orderBy('temp.temp_diasem_codigo')
             ->get();

            // Agrupar por periodo
            $periodos = [];
            $temporalesAgrupados = $horariosTemporales->groupBy(function($item) {
                return $item->temp_fecha_inicio . '_' . $item->temp_fecha_fin . '_' . $item->temp_tipo;
            });

            foreach ($temporalesAgrupados as $grupo) {
                $primer = $grupo->first();
                $fechaInicioPeriodo = Carbon::parse($primer->temp_fecha_inicio);
                $fechaFinPeriodo = Carbon::parse($primer->temp_fecha_fin);
                
                $periodos[] = [
                    'id_grupo' => $primer->temp_fecha_inicio . '_' . $primer->temp_fecha_fin,
                    'fecha_inicio' => $primer->temp_fecha_inicio,
                    'fecha_fin' => $primer->temp_fecha_fin,
                    'duracion_dias' => $fechaInicioPeriodo->diffInDays($fechaFinPeriodo) + 1,
                    'tipo_temporal' => $primer->temp_tipo,
                    'motivo' => $primer->temp_motivo,
                    'activo' => $primer->temp_activo,
                    'esta_vigente' => Carbon::today()->between($fechaInicioPeriodo, $fechaFinPeriodo),
                    'dias_restantes' => Carbon::today() <= $fechaFinPeriodo ? 
                        Carbon::today()->diffInDays($fechaFinPeriodo, false) : 0,
                    'horarios_por_dia' => $grupo->map(function($horario) {
                        return [
                            'temp_id' => $horario->temp_id,
                            'dia_codigo' => $horario->temp_diasem_codigo,
                            'dia_nombre' => trim($horario->diasem_nombre),
                            'dia_abreviatura' => trim($horario->diasem_abreviatura),
                            'hora_entrada' => $horario->temp_horentrada,
                            'hora_salida' => $horario->temp_horsalida,
                            'formato_visual' => Carbon::parse($horario->temp_horentrada)->format('H:i') . ' - ' . 
                                              Carbon::parse($horario->temp_horsalida)->format('H:i'),
                            'jornada' => Carbon::parse($horario->temp_horentrada)->hour < 12 ? 'MATUTINA' : 'NOCTURNA'
                        ];
                    })->sortBy('dia_codigo')->values()
                ];
            }

            // Estadísticas
            $stats = [
                'total_periodos' => count($periodos),
                'periodos_activos' => collect($periodos)->where('activo', true)->count(),
                'periodos_vigentes' => collect($periodos)->where('esta_vigente', true)->count(),
                'total_dias_temporales' => $horariosTemporales->count(),
                'tipos_utilizados' => $horariosTemporales->pluck('temp_tipo')->unique()->values()
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Horarios temporales obtenidos correctamente',
                'data' => [
                    'usuario_id' => $usuarioId,
                    'periodos_temporales' => $periodos,
                    'estadisticas' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo horarios temporales: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener horarios temporales: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Eliminar horario temporal específico
     * DELETE /api/usuarios/{usuarioId}/horarios/temporal/{temporalId}
     */
    public function eliminarHorarioTemporal($usuarioId, $temporalId, Request $request)
    {
        try {
            Log::info("🗑️ Eliminando horario temporal {$temporalId} del usuario {$usuarioId}");

            $eliminarTodoPeriodo = $request->get('eliminar_todo_periodo', false);

            // Verificar que el horario temporal existe
            $horarioTemporal = DB::table('gaf_jorusu_temp')
                ->where('temp_id', $temporalId)
                ->where('temp_usu_id', $usuarioId)
                ->first();

            if (!$horarioTemporal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Horario temporal no encontrado',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            if ($eliminarTodoPeriodo) {
                // Eliminar todo el periodo (mismo rango de fechas y tipo)
                $eliminados = DB::table('gaf_jorusu_temp')
                    ->where('temp_usu_id', $usuarioId)
                    ->where('temp_fecha_inicio', $horarioTemporal->temp_fecha_inicio)
                    ->where('temp_fecha_fin', $horarioTemporal->temp_fecha_fin)
                    ->where('temp_tipo', $horarioTemporal->temp_tipo)
                    ->delete();

                $mensaje = "Periodo temporal completo eliminado ({$eliminados} días)";
            } else {
                // Eliminar solo este día específico
                DB::table('gaf_jorusu_temp')
                    ->where('temp_id', $temporalId)
                    ->delete();

                $eliminados = 1;
                $mensaje = "Horario temporal de un día eliminado";
            }

            DB::commit();

            Log::info("✅ Horario temporal eliminado exitosamente:", [
                'temporal_id' => $temporalId,
                'usuario_id' => $usuarioId,
                'eliminados' => $eliminados,
                'periodo_completo' => $eliminarTodoPeriodo
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $mensaje,
                'data' => [
                    'temporal_id' => $temporalId,
                    'usuario_id' => $usuarioId,
                    'registros_eliminados' => $eliminados,
                    'periodo_eliminado' => [
                        'fecha_inicio' => $horarioTemporal->temp_fecha_inicio,
                        'fecha_fin' => $horarioTemporal->temp_fecha_fin,
                        'tipo' => $horarioTemporal->temp_tipo
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error eliminando horario temporal: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar horario temporal: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener horario efectivo para una fecha específica (incluye temporales)
     * GET /api/usuarios/{usuarioId}/horario-efectivo?fecha=YYYY-MM-DD
     */
    public function horarioEfectivoFecha($usuarioId, Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha' => 'required|date_format:Y-m-d'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Fecha inválida',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            $fecha = Carbon::parse($request->fecha);
            $diaSemana = $fecha->dayOfWeekIso;

            // Verificar usuario
            $usuario = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();
            if (!$usuario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado',
                    'data' => null
                ], 404);
            }

            $horarioEfectivo = null;
            $origenHorario = 'NINGUNO';

            // 1. Buscar horario temporal primero (máxima prioridad)
            $horarioTemporal = DB::table('gaf_jorusu_temp')
                ->where('temp_usu_id', $usuarioId)
                ->where('temp_diasem_codigo', $diaSemana)
                ->where('temp_fecha_inicio', '<=', $fecha)
                ->where('temp_fecha_fin', '>=', $fecha)
                ->where('temp_activo', true)
                ->first();

            if ($horarioTemporal) {
                $horarioEfectivo = [
                    'hora_entrada' => $horarioTemporal->temp_horentrada,
                    'hora_salida' => $horarioTemporal->temp_horsalida,
                    'motivo' => $horarioTemporal->temp_motivo,
                    'tipo' => $horarioTemporal->temp_tipo
                ];
                $origenHorario = 'TEMPORAL';
            } else {
                // 2. Buscar horario personalizado permanente
                $horarioPersonalizado = DB::table('gaf_jorusu')
                    ->where('jorusu_usu_id', $usuarioId)
                    ->where('jorusu_diasem_codigo', $diaSemana)
                    ->first();

                if ($horarioPersonalizado) {
                    $horarioEfectivo = [
                        'hora_entrada' => $horarioPersonalizado->jorusu_horentrada,
                        'hora_salida' => $horarioPersonalizado->jorusu_horsalida
                    ];
                    $origenHorario = 'PERSONALIZADO';
                } elseif ($usuario->oficin_codigo) {
                    // 3. Buscar horario de oficina
                    $horarioOficina = DB::table('gaf_jorofi')
                        ->where('jorofi_oficin_codigo', $usuario->oficin_codigo)
                        ->where('jorofi_diasem_codigo', $diaSemana)
                        ->where('jorofi_ctrhabil', 1)
                        ->first();

                    if ($horarioOficina) {
                        $horarioEfectivo = [
                            'hora_entrada' => $horarioOficina->jorofi_horinicial,
                            'hora_salida' => $horarioOficina->jorofi_horfinal
                        ];
                        $origenHorario = 'HEREDADO_OFICINA';
                    }
                }
            }

            $response = [
                'usuario_id' => $usuarioId,
                'fecha_consultada' => $request->fecha,
                'dia_semana' => $diaSemana,
                'tiene_horario' => $horarioEfectivo !== null,
                'origen_horario' => $origenHorario,
                'horario_efectivo' => $horarioEfectivo
            ];

            if ($horarioEfectivo) {
                $horaEntrada = Carbon::createFromFormat('H:i', $horarioEfectivo['hora_entrada']);
                $horaSalida = Carbon::createFromFormat('H:i', $horarioEfectivo['hora_salida']);
                
                $response['detalle_horario'] = [
                    'formato_visual' => $horarioEfectivo['hora_entrada'] . ' - ' . $horarioEfectivo['hora_salida'],
                    'jornada' => $horaEntrada->hour < 12 ? 'MATUTINA' : 'NOCTURNA',
                    'cruza_medianoche' => $horaSalida < $horaEntrada,
                    'duracion_horas' => $horaSalida < $horaEntrada ? 
                        (24 - $horaEntrada->hour) + $horaSalida->hour : 
                        $horaSalida->diffInHours($horaEntrada)
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Horario efectivo obtenido correctamente',
                'data' => $response
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo horario efectivo: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener horario efectivo: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Limpiar horarios temporales vencidos
     * DELETE /api/usuarios/horarios/temporales/limpiar-vencidos
     */
    public function limpiarHorariosVencidos(Request $request)
    {
        try {
            Log::info("🧹 Limpiando horarios temporales vencidos");

            $diasVencimiento = $request->get('dias_vencimiento', 30); // Eliminar los que llevan 30+ días vencidos
            $fechaLimite = Carbon::now()->subDays($diasVencimiento);

            // Contar registros a eliminar
            $registrosAEliminar = DB::table('gaf_jorusu_temp')
                ->where('temp_fecha_fin', '<', $fechaLimite)
                ->count();

            if ($registrosAEliminar === 0) {
                return response()->json([
                    'status' => 'info',
                    'message' => 'No hay horarios temporales vencidos para eliminar',
                    'data' => [
                        'fecha_limite' => $fechaLimite->format('Y-m-d'),
                        'registros_eliminados' => 0
                    ]
                ]);
            }

            DB::beginTransaction();

            // Eliminar registros vencidos
            DB::table('gaf_jorusu_temp')
                ->where('temp_fecha_fin', '<', $fechaLimite)
                ->delete();

            DB::commit();

            Log::info("✅ Horarios temporales vencidos eliminados:", [
                'registros_eliminados' => $registrosAEliminar,
                'fecha_limite' => $fechaLimite->format('Y-m-d')
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se eliminaron {$registrosAEliminar} horarios temporales vencidos",
                'data' => [
                    'fecha_limite' => $fechaLimite->format('Y-m-d'),
                    'registros_eliminados' => $registrosAEliminar,
                    'dias_vencimiento' => $diasVencimiento
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error limpiando horarios vencidos: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al limpiar horarios vencidos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
    

    /**
     * ✅ VALIDAR HORARIO INDIVIDUAL CONTRA HORARIO DE OFICINA
     */
    private function validarContraHorarioOficina($oficinaCodigo, $diaCodigo, $horaEntrada, $horaSalida)
    {
        try {
            // Obtener horario de oficina para el día específico
            $horarioOficina = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaCodigo)
                ->where('gaf_jorofi.jorofi_diasem_codigo', $diaCodigo)
                ->where('gaf_jorofi.jorofi_ctrhabil', 1)
                ->select('gaf_jorofi.*', 'gaf_diasem.diasem_nombre')
                ->first();

            if (!$horarioOficina) {
                return [
                    'valido' => false,
                    'motivo' => 'La oficina no tiene horario configurado para este día',
                    'dia_nombre' => null,
                    'horario_oficina' => null
                ];
            }

            $entradaUsuario = Carbon::createFromFormat('H:i', $horaEntrada);
            $salidaUsuario = Carbon::createFromFormat('H:i', $horaSalida);
            $entradaOficina = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horinicial);
            $salidaOficina = Carbon::createFromFormat('H:i', $horarioOficina->jorofi_horfinal);

            // Manejar horarios que cruzan medianoche
            $oficinaCruzaMedianoche = $salidaOficina < $entradaOficina;
            $usuarioCruzaMedianoche = $salidaUsuario < $entradaUsuario;

            $validoHorario = false;

            if ($oficinaCruzaMedianoche) {
                // La oficina cruza medianoche (ej: 22:00 - 06:00)
                if ($usuarioCruzaMedianoche) {
                    // Usuario también cruza medianoche
                    $validoHorario = ($entradaUsuario >= $entradaOficina || $entradaUsuario <= $salidaOficina) &&
                                   ($salidaUsuario >= $entradaOficina || $salidaUsuario <= $salidaOficina);
                } else {
                    // Usuario no cruza medianoche
                    $validoHorario = ($entradaUsuario >= $entradaOficina || $entradaUsuario <= $salidaOficina) &&
                                   ($salidaUsuario >= $entradaOficina || $salidaUsuario <= $salidaOficina);
                }
            } else {
                // Horario normal de oficina
                $validoHorario = $entradaUsuario >= $entradaOficina && $salidaUsuario <= $salidaOficina;
            }

            return [
                'valido' => $validoHorario,
                'motivo' => $validoHorario ? 'Horario válido' : 'Horario fuera del rango de oficina',
                'dia_nombre' => trim($horarioOficina->diasem_nombre),
                'horario_oficina' => [
                    'hora_entrada' => $horarioOficina->jorofi_horinicial,
                    'hora_salida' => $horarioOficina->jorofi_horfinal,
                    'cruza_medianoche' => $oficinaCruzaMedianoche
                ],
                'horario_usuario' => [
                    'hora_entrada' => $horaEntrada,
                    'hora_salida' => $horaSalida,
                    'cruza_medianoche' => $usuarioCruzaMedianoche
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Error validando horario contra oficina: " . $e->getMessage());
            return [
                'valido' => false,
                'motivo' => 'Error en validación: ' . $e->getMessage(),
                'dia_nombre' => null,
                'horario_oficina' => null
            ];
        }
    }

    /**
     * ✅ GENERAR SUGERENCIAS DE HORARIO
     */
    private function generarSugerenciasHorario($validacionOficina)
    {
        $sugerencias = [];

        if (isset($validacionOficina['horario_oficina'])) {
            $horarioOficina = $validacionOficina['horario_oficina'];
            
            $sugerencias[] = [
                'tipo' => 'horario_completo_oficina',
                'descripcion' => 'Usar el horario completo de la oficina',
                'hora_entrada' => $horarioOficina['hora_entrada'],
                'hora_salida' => $horarioOficina['hora_salida']
            ];

            // Sugerir horario reducido dentro del rango
            $entradaOficina = Carbon::createFromFormat('H:i', $horarioOficina['hora_entrada']);
            $salidaOficina = Carbon::createFromFormat('H:i', $horarioOficina['hora_salida']);

            if (!$horarioOficina['cruza_medianoche']) {
                // Sugerir entrada 1 hora después
                $entradaSugerida = $entradaOficina->copy()->addHour();
                if ($entradaSugerida < $salidaOficina) {
                    $sugerencias[] = [
                        'tipo' => 'entrada_tardia',
                        'descripcion' => 'Entrada 1 hora después del inicio de oficina',
                        'hora_entrada' => $entradaSugerida->format('H:i'),
                        'hora_salida' => $horarioOficina['hora_salida']
                    ];
                }

                // Sugerir salida 1 hora antes
                $salidaSugerida = $salidaOficina->copy()->subHour();
                if ($salidaSugerida > $entradaOficina) {
                    $sugerencias[] = [
                        'tipo' => 'salida_temprana',
                        'descripcion' => 'Salida 1 hora antes del cierre de oficina',
                        'hora_entrada' => $horarioOficina['hora_entrada'],
                        'hora_salida' => $salidaSugerida->format('H:i')
                    ];
                }
            }
        }

        return $sugerencias;
    }

    /**
     * ✅ BATCH CON VALIDACIÓN AUTOMÁTICA
     */
    

    /**
     * ✅ MÉTODO PARA OBTENER HORARIO EFECTIVO (USADO POR MIDDLEWARE)
     */
    public function obtenerHorarioEfectivo($usuarioId, $fecha = null)
    {
        try {
            $fechaConsulta = $fecha ? Carbon::parse($fecha) : Carbon::now('America/Guayaquil');
            $diaSemana = $fechaConsulta->dayOfWeekIso;

            $usuario = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();
            if (!$usuario) {
                return [
                    'horario' => null,
                    'origen' => 'USUARIO_NO_ENCONTRADO',
                    'mensaje' => 'Usuario no encontrado'
                ];
            }

            // 🥇 PRIORIDAD 1: Horario temporal
            $horarioTemporal = DB::table('gaf_jorusu_temp')
                ->where('temp_usu_id', $usuarioId)
                ->where('temp_diasem_codigo', $diaSemana)
                ->where('temp_fecha_inicio', '<=', $fechaConsulta->format('Y-m-d'))
                ->where('temp_fecha_fin', '>=', $fechaConsulta->format('Y-m-d'))
                ->where('temp_activo', true)
                ->first();

            if ($horarioTemporal) {
                return [
                    'horario' => [
                        'hora_entrada' => $horarioTemporal->temp_horentrada,
                        'hora_salida' => $horarioTemporal->temp_horsalida
                    ],
                    'origen' => 'TEMPORAL',
                    'mensaje' => 'Horario temporal activo',
                    'info_adicional' => [
                        'tipo' => $horarioTemporal->temp_tipo,
                        'motivo' => $horarioTemporal->temp_motivo,
                        'fecha_fin' => $horarioTemporal->temp_fecha_fin
                    ]
                ];
            }

            // 🥈 PRIORIDAD 2: Horario personalizado
            $horarioPersonalizado = DB::table('gaf_jorusu')
                ->where('jorusu_usu_id', $usuarioId)
                ->where('jorusu_diasem_codigo', $diaSemana)
                ->first();

            if ($horarioPersonalizado) {
                return [
                    'horario' => [
                        'hora_entrada' => $horarioPersonalizado->jorusu_horentrada,
                        'hora_salida' => $horarioPersonalizado->jorusu_horsalida
                    ],
                    'origen' => 'PERSONALIZADO',
                    'mensaje' => 'Horario personalizado del usuario'
                ];
            }

            // 🥉 PRIORIDAD 3: Horario de oficina
            if ($usuario->oficin_codigo) {
                $horarioOficina = DB::table('gaf_jorofi')
                    ->where('jorofi_oficin_codigo', $usuario->oficin_codigo)
                    ->where('jorofi_diasem_codigo', $diaSemana)
                    ->where('jorofi_ctrhabil', 1)
                    ->first();

                if ($horarioOficina) {
                    return [
                        'horario' => [
                            'hora_entrada' => $horarioOficina->jorofi_horinicial,
                            'hora_salida' => $horarioOficina->jorofi_horfinal
                        ],
                        'origen' => 'HEREDADO_OFICINA',
                        'mensaje' => 'Horario heredado de la oficina'
                    ];
                }
            }

            // ❌ Sin horario
            return [
                'horario' => null,
                'origen' => 'SIN_HORARIO',
                'mensaje' => 'No hay horario configurado'
            ];

        } catch (\Exception $e) {
            Log::error("Error obteniendo horario efectivo: " . $e->getMessage());
            return [
                'horario' => null,
                'origen' => 'ERROR',
                'mensaje' => 'Error al obtener horario'
            ];
        }
    }
}