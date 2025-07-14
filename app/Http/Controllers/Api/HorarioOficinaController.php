<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HorarioOficinaController extends Controller
{
    /**
     * Obtener horarios de una oficina específica
     * GET /api/oficinas/{oficinaId}/horarios
     */
    public function index($oficinaId, Request $request)
    {
        try {
            Log::info("🕐 Obteniendo horarios para oficina ID: {$oficinaId}");
            
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

            // Obtener horarios con información del día de la semana
            $horarios = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaId)
                ->select(
                    'gaf_jorofi.jorofi_oficin_codigo',
                    'gaf_jorofi.jorofi_diasem_codigo',
                    'gaf_jorofi.jorofi_horinicial',
                    'gaf_jorofi.jorofi_horfinal',
                    'gaf_jorofi.jorofi_ctrhabil',
                    'gaf_diasem.diasem_nombre',
                    'gaf_diasem.diasem_abreviatura',
                    DB::raw("CASE 
                        WHEN gaf_jorofi.jorofi_ctrhabil = 1 THEN 'ACTIVO'
                        ELSE 'INACTIVO'
                    END as estado_dia"),
                    DB::raw("CASE 
                        WHEN EXTRACT(HOUR FROM gaf_jorofi.jorofi_horinicial) < 12 THEN 'MATUTINA'
                        ELSE 'NOCTURNA'
                    END as jornada_tipo")
                )
                ->orderBy('gaf_jorofi.jorofi_diasem_codigo')
                ->get();

            // Obtener información completa de la oficina
            $oficinaInfo = DB::table('gaf_oficin')
                ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
                ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
                ->where('gaf_oficin.oficin_codigo', $oficinaId)
                ->select(
                    'gaf_oficin.oficin_codigo',
                    'gaf_oficin.oficin_nombre',
                    'gaf_oficin.oficin_direccion',
                    'gaf_oficin.oficin_ctractual',
                    'gaf_instit.instit_nombre',
                    'gaf_tofici.tofici_descripcion as tipo_oficina'
                )
                ->first();

            // Crear array de días completo (1-7, Lunes a Domingo)
            $diasCompletos = [];
            $diasSemana = DB::table('gaf_diasem')
                ->orderBy('diasem_codigo')
                ->get();

            foreach ($diasSemana as $dia) {
                $horarioDia = $horarios->where('jorofi_diasem_codigo', $dia->diasem_codigo)->first();
                
                $diasCompletos[] = [
                    'dia_codigo' => $dia->diasem_codigo,
                    'dia_nombre' => trim($dia->diasem_nombre),
                    'dia_abreviatura' => trim($dia->diasem_abreviatura),
                    'tiene_horario' => $horarioDia ? true : false,
                    'hora_inicio' => $horarioDia ? $horarioDia->jorofi_horinicial : null,
                    'hora_fin' => $horarioDia ? $horarioDia->jorofi_horfinal : null,
                    'activo' => $horarioDia ? ($horarioDia->jorofi_ctrhabil == 1) : false,
                    'jornada' => $horarioDia ? $horarioDia->jornada_tipo : null,
                    'formato_visual' => $horarioDia ? 
                        Carbon::parse($horarioDia->jorofi_horinicial)->format('H:i') . ' - ' . 
                        Carbon::parse($horarioDia->jorofi_horfinal)->format('H:i') : 
                        'Sin horario'
                ];
            }

            // Estadísticas
            $stats = [
                'total_dias_configurados' => $horarios->count(),
                'dias_activos' => $horarios->where('jorofi_ctrhabil', 1)->count(),
                'dias_inactivos' => $horarios->where('jorofi_ctrhabil', 0)->count(),
                'jornadas_matutinas' => $horarios->where('jornada_tipo', 'MATUTINA')->count(),
                'jornadas_nocturnas' => $horarios->where('jornada_tipo', 'NOCTURNA')->count(),
                'oficina_operativa' => $horarios->where('jorofi_ctrhabil', 1)->count() > 0
            ];

            Log::info("✅ Horarios obtenidos correctamente:", [
                'oficina_id' => $oficinaId,
                'dias_configurados' => $stats['total_dias_configurados'],
                'dias_activos' => $stats['dias_activos']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Horarios obtenidos correctamente',
                'data' => [
                    'oficina' => $oficinaInfo,
                    'horarios_por_dia' => $diasCompletos,
                    'estadisticas' => $stats,
                    'configuracion_completa' => $stats['total_dias_configurados'] === 7
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo horarios oficina {$oficinaId}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener horarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Crear o actualizar horario para un día específico
     * POST /api/oficinas/{oficinaId}/horarios
     */
    public function store($oficinaId, Request $request)
    {
        try {
            Log::info("🕐 Creando/actualizando horario para oficina {$oficinaId}");
            
            $validator = Validator::make($request->all(), [
                'dia_codigo' => 'required|integer|between:1,7|exists:gaf_diasem,diasem_codigo',
                'hora_inicio' => 'required|date_format:H:i',
                'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
                'activo' => 'required|boolean',
                'observaciones' => 'nullable|string|max:200'
            ], [
                'dia_codigo.required' => 'El día de la semana es requerido',
                'dia_codigo.between' => 'El día debe estar entre 1 (Lunes) y 7 (Domingo)',
                'dia_codigo.exists' => 'El día seleccionado no es válido',
                'hora_inicio.required' => 'La hora de inicio es requerida',
                'hora_inicio.date_format' => 'La hora de inicio debe tener formato HH:MM',
                'hora_fin.required' => 'La hora de fin es requerida',
                'hora_fin.date_format' => 'La hora de fin debe tener formato HH:MM',
                'hora_fin.after' => 'La hora de fin debe ser posterior a la hora de inicio',
                'activo.required' => 'El estado activo es requerido',
                'activo.boolean' => 'El estado activo debe ser verdadero o falso'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

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

            // Validación adicional: verificar solapamiento de horarios en el mismo día
            $horaInicio = Carbon::createFromFormat('H:i', $request->hora_inicio);
            $horaFin = Carbon::createFromFormat('H:i', $request->hora_fin);

            // Verificar si el horario cruza medianoche
            $cruzaMedianoche = $horaFin < $horaInicio;
            if ($cruzaMedianoche) {
                // Si cruza medianoche, validar que sea coherente (ej: 22:00 - 06:00)
                if ($horaInicio->hour < 18 || $horaFin->hour > 10) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Horario que cruza medianoche debe ser coherente (ej: 22:00 - 06:00)',
                        'data' => null
                    ], 422);
                }
            }

            DB::beginTransaction();

            $diaData = [
                'jorofi_oficin_codigo' => $oficinaId,
                'jorofi_diasem_codigo' => $request->dia_codigo,
                'jorofi_horinicial' => $request->hora_inicio,
                'jorofi_horfinal' => $request->hora_fin,
                'jorofi_ctrhabil' => $request->activo ? 1 : 0
            ];

            // Verificar si ya existe horario para este día
            $horarioExistente = DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaId)
                ->where('jorofi_diasem_codigo', $request->dia_codigo)
                ->first();

            $operacion = '';
            if ($horarioExistente) {
                // Actualizar horario existente
                DB::table('gaf_jorofi')
                    ->where('jorofi_oficin_codigo', $oficinaId)
                    ->where('jorofi_diasem_codigo', $request->dia_codigo)
                    ->update($diaData);
                $operacion = 'actualizado';
            } else {
                // Crear nuevo horario
                DB::table('gaf_jorofi')->insert($diaData);
                $operacion = 'creado';
            }

            // Obtener información del día configurado
            $diaInfo = DB::table('gaf_diasem')
                ->where('diasem_codigo', $request->dia_codigo)
                ->first();

            $horarioConfigurado = [
                'oficina_id' => $oficinaId,
                'dia_codigo' => $request->dia_codigo,
                'dia_nombre' => trim($diaInfo->diasem_nombre),
                'hora_inicio' => $request->hora_inicio,
                'hora_fin' => $request->hora_fin,
                'activo' => $request->activo,
                'jornada' => $horaInicio->hour < 12 ? 'MATUTINA' : 'NOCTURNA',
                'formato_visual' => "{$request->hora_inicio} - {$request->hora_fin}",
                'cruza_medianoche' => $cruzaMedianoche,
                'duracion_horas' => $cruzaMedianoche ? 
                    (24 - $horaInicio->hour) + $horaFin->hour : 
                    $horaFin->diffInHours($horaInicio)
            ];

            DB::commit();

            Log::info("✅ Horario {$operacion} exitosamente:", [
                'oficina_id' => $oficinaId,
                'dia' => $diaInfo->diasem_nombre,
                'horario' => "{$request->hora_inicio} - {$request->hora_fin}",
                'activo' => $request->activo
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Horario {$operacion} exitosamente para " . trim($diaInfo->diasem_nombre),
                'data' => $horarioConfigurado
            ], $horarioExistente ? 200 : 201);

        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            Log::error("❌ Error de base de datos creando horario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error de base de datos al guardar horario',
                'data' => [
                    'error_code' => $e->getCode(),
                    'sql_state' => $e->errorInfo[0] ?? null
                ]
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error eliminando todos los horarios: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar horarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
    public function copiarHorarios($oficinaOrigenId, $oficinaDestinoId, Request $request)
    {
        try {
            Log::info("📋 Copiando horarios de oficina {$oficinaOrigenId} a {$oficinaDestinoId}");
            
            // Validar parámetros adicionales
            $validator = Validator::make($request->all(), [
                'sobrescribir_existentes' => 'boolean',
                'copiar_solo_activos' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Verificar que ambas oficinas existen
            $oficinaOrigen = DB::table('gaf_oficin')->where('oficin_codigo', $oficinaOrigenId)->first();
            $oficinaDestino = DB::table('gaf_oficin')->where('oficin_codigo', $oficinaDestinoId)->first();

            if (!$oficinaOrigen) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oficina origen no encontrada',
                    'data' => null
                ], 404);
            }

            if (!$oficinaDestino) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oficina destino no encontrada',
                    'data' => null
                ], 404);
            }

            if ($oficinaOrigenId == $oficinaDestinoId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede copiar horarios a la misma oficina',
                    'data' => null
                ], 422);
            }

            // Obtener horarios de la oficina origen
            $query = DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaOrigenId);

            if ($request->get('copiar_solo_activos', false)) {
                $query->where('jorofi_ctrhabil', 1);
            }

            $horariosOrigen = $query->get();

            if ($horariosOrigen->isEmpty()) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'La oficina origen no tiene horarios configurados',
                    'data' => [
                        'oficina_origen' => $oficinaOrigenId,
                        'oficina_destino' => $oficinaDestinoId,
                        'horarios_copiados' => 0
                    ]
                ]);
            }

            $sobrescribirExistentes = $request->get('sobrescribir_existentes', true);

            DB::beginTransaction();

            $resultados = [
                'copiados' => 0,
                'saltados' => 0,
                'actualizados' => 0,
                'errores' => []
            ];

            foreach ($horariosOrigen as $horario) {
                try {
                    // Verificar si ya existe horario para este día en destino
                    $horarioExistente = DB::table('gaf_jorofi')
                        ->where('jorofi_oficin_codigo', $oficinaDestinoId)
                        ->where('jorofi_diasem_codigo', $horario->jorofi_diasem_codigo)
                        ->first();

                    if ($horarioExistente && !$sobrescribirExistentes) {
                        $resultados['saltados']++;
                        continue;
                    }

                    $nuevoHorario = [
                        'jorofi_oficin_codigo' => $oficinaDestinoId,
                        'jorofi_diasem_codigo' => $horario->jorofi_diasem_codigo,
                        'jorofi_horinicial' => $horario->jorofi_horinicial,
                        'jorofi_horfinal' => $horario->jorofi_horfinal,
                        'jorofi_ctrhabil' => $horario->jorofi_ctrhabil
                    ];

                    if ($horarioExistente) {
                        DB::table('gaf_jorofi')
                            ->where('jorofi_oficin_codigo', $oficinaDestinoId)
                            ->where('jorofi_diasem_codigo', $horario->jorofi_diasem_codigo)
                            ->update($nuevoHorario);
                        $resultados['actualizados']++;
                    } else {
                        DB::table('gaf_jorofi')->insert($nuevoHorario);
                        $resultados['copiados']++;
                    }

                } catch (\Exception $e) {
                    $resultados['errores'][] = [
                        'dia_codigo' => $horario->jorofi_diasem_codigo,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            $totalProcesados = $resultados['copiados'] + $resultados['actualizados'];

            Log::info("✅ Horarios copiados exitosamente:", [
                'origen' => $oficinaOrigenId,
                'destino' => $oficinaDestinoId,
                'copiados' => $resultados['copiados'],
                'actualizados' => $resultados['actualizados'],
                'saltados' => $resultados['saltados']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se procesaron {$totalProcesados} horarios correctamente",
                'data' => [
                    'oficina_origen' => $oficinaOrigenId,
                    'oficina_destino' => $oficinaDestinoId,
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
     * Obtener estadísticas generales de horarios de todas las oficinas
     * GET /api/horarios/estadisticas
     */
    

    /**
     * Validar si una oficina está operativa en un momento específico
     * GET /api/oficinas/{oficinaId}/validar-horario?fecha=YYYY-MM-DD&hora=HH:MM
     */
   

    /**
     * Obtener horario actual de una oficina (para uso interno del sistema)
     * GET /api/oficinas/{oficinaId}/horario-actual
     */
    

    /**
     * Obtener próximos horarios de una oficina (siguientes 7 días)
     * GET /api/oficinas/{oficinaId}/proximos-horarios
     */
    

    /**
     * Activar/desactivar horario de un día específico
     * PUT /api/oficinas/{oficinaId}/horarios/{diaId}/toggle
     */
    

    /**
     * Obtener plantillas de horarios predefinidas
     * GET /api/horarios/plantillas
     */
    

    /**
     * Aplicar plantilla de horarios a una oficina
     * POST /api/oficinas/{oficinaId}/horarios/aplicar-plantilla
     */
    
    /**
     * Crear/actualizar múltiples horarios de una vez
     * POST /api/oficinas/{oficinaId}/horarios/batch
     */
    public function storeBatch($oficinaId, Request $request)
    {
        try {
            Log::info("🕐 Creando/actualizando horarios múltiples para oficina {$oficinaId}");
            
            $validator = Validator::make($request->all(), [
                'horarios' => 'required|array|min:1|max:7',
                'horarios.*.dia_codigo' => 'required|integer|between:1,7|exists:gaf_diasem,diasem_codigo',
                'horarios.*.hora_inicio' => 'required|date_format:H:i',
                'horarios.*.hora_fin' => 'required|date_format:H:i',
                'horarios.*.activo' => 'required|boolean',
                'sobrescribir_existentes' => 'boolean'
            ], [
                'horarios.required' => 'Los horarios son requeridos',
                'horarios.array' => 'Los horarios deben ser un array',
                'horarios.min' => 'Debe proporcionar al menos un horario',
                'horarios.max' => 'No puede proporcionar más de 7 horarios (uno por día)',
                'horarios.*.dia_codigo.required' => 'El día de la semana es requerido para cada horario',
                'horarios.*.dia_codigo.between' => 'El día debe estar entre 1 (Lunes) y 7 (Domingo)',
                'horarios.*.hora_inicio.required' => 'La hora de inicio es requerida para cada horario',
                'horarios.*.hora_fin.required' => 'La hora de fin es requerida para cada horario'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

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

            // Validar que no haya días duplicados en el request
            $diasEnviados = array_column($request->horarios, 'dia_codigo');
            if (count($diasEnviados) !== count(array_unique($diasEnviados))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No puede enviar horarios duplicados para el mismo día',
                    'data' => null
                ], 422);
            }

            // Validar horarios individuales
            foreach ($request->horarios as $index => $horario) {
                $horaInicio = Carbon::createFromFormat('H:i', $horario['hora_inicio']);
                $horaFin = Carbon::createFromFormat('H:i', $horario['hora_fin']);
                
                if ($horaFin <= $horaInicio && !($horaInicio->hour >= 18 && $horaFin->hour <= 10)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Horario inválido en posición {$index}: La hora de fin debe ser posterior a la hora de inicio",
                        'data' => null
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
                    $horarioExistente = DB::table('gaf_jorofi')
                        ->where('jorofi_oficin_codigo', $oficinaId)
                        ->where('jorofi_diasem_codigo', $horario['dia_codigo'])
                        ->first();

                    if ($horarioExistente && !$sobrescribirExistentes) {
                        $resultados['saltados']++;
                        continue;
                    }

                    $diaData = [
                        'jorofi_oficin_codigo' => $oficinaId,
                        'jorofi_diasem_codigo' => $horario['dia_codigo'],
                        'jorofi_horinicial' => $horario['hora_inicio'],
                        'jorofi_horfinal' => $horario['hora_fin'],
                        'jorofi_ctrhabil' => $horario['activo'] ? 1 : 0
                    ];

                    if ($horarioExistente) {
                        DB::table('gaf_jorofi')
                            ->where('jorofi_oficin_codigo', $oficinaId)
                            ->where('jorofi_diasem_codigo', $horario['dia_codigo'])
                            ->update($diaData);
                        $resultados['actualizados']++;
                    } else {
                        DB::table('gaf_jorofi')->insert($diaData);
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
            
            Log::info("✅ Horarios batch procesados:", [
                'oficina_id' => $oficinaId,
                'creados' => $resultados['creados'],
                'actualizados' => $resultados['actualizados'],
                'saltados' => $resultados['saltados'],
                'errores' => count($resultados['errores'])
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se procesaron {$totalProcesados} horarios correctamente",
                'data' => [
                    'oficina_id' => $oficinaId,
                    'resultados' => $resultados,
                    'total_procesados' => $totalProcesados
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error en storeBatch horarios: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar horarios múltiples: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Eliminar horario de un día específico
     * DELETE /api/oficinas/{oficinaId}/horarios/{diaId}
     */
    public function destroy($oficinaId, $diaId)
    {
        try {
            Log::info("🗑️ Eliminando horario oficina {$oficinaId}, día {$diaId}");
            
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
            $horario = DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaId)
                ->where('jorofi_diasem_codigo', $diaId)
                ->first();

            if (!$horario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No existe horario configurado para este día',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaId)
                ->where('jorofi_diasem_codigo', $diaId)
                ->delete();

            $horarioEliminado = [
                'oficina_id' => $oficinaId,
                'dia_codigo' => $diaId,
                'dia_nombre' => trim($dia->diasem_nombre),
                'horario_eliminado' => $horario->jorofi_horinicial . ' - ' . $horario->jorofi_horfinal
            ];

            DB::commit();

            Log::info("✅ Horario eliminado exitosamente:", $horarioEliminado);

            return response()->json([
                'status' => 'success',
                'message' => 'Horario eliminado exitosamente para ' . trim($dia->diasem_nombre),
                'data' => $horarioEliminado
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error eliminando horario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar horario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Eliminar todos los horarios de una oficina
     * DELETE /api/oficinas/{oficinaId}/horarios
     */
    /**
     * Obtener próximos horarios de una oficina (siguientes 7 días)
     * GET /api/oficinas/{oficinaId}/proximos-horarios
     */

    
    

    public function calendario($oficinaId, Request $request)
    {
        try {
            Log::info("📅 Obteniendo vista calendario para oficina {$oficinaId}");
            
            // Obtener mes y año del request, por defecto el actual
            $mes = $request->get('mes', date('m'));
            $anio = $request->get('anio', date('Y'));
            
            // Validar mes y año
            if (!is_numeric($mes) || $mes < 1 || $mes > 12) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Mes inválido, debe estar entre 1 y 12',
                    'data' => null
                ], 422);
            }

            if (!is_numeric($anio) || $anio < 2020 || $anio > 2030) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Año inválido, debe estar entre 2020 y 2030',
                    'data' => null
                ], 422);
            }

            // Verificar que la oficina existe
            $oficina = DB::table('gaf_oficin')
                ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
                ->leftJoin('gaf_tofici', 'gaf_oficin.oficin_tofici_codigo', '=', 'gaf_tofici.tofici_codigo')
                ->where('gaf_oficin.oficin_codigo', $oficinaId)
                ->select(
                    'gaf_oficin.oficin_codigo',
                    'gaf_oficin.oficin_nombre',
                    'gaf_oficin.oficin_direccion',
                    'gaf_oficin.oficin_ctractual',
                    'gaf_instit.instit_nombre',
                    'gaf_tofici.tofici_descripcion as tipo_oficina'
                )
                ->first();

            if (!$oficina) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oficina no encontrada',
                    'data' => null
                ], 404);
            }

            // Obtener horarios configurados
            $horarios = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaId)
                ->select(
                    'gaf_jorofi.jorofi_diasem_codigo',
                    'gaf_jorofi.jorofi_horinicial',
                    'gaf_jorofi.jorofi_horfinal',
                    'gaf_jorofi.jorofi_ctrhabil',
                    'gaf_diasem.diasem_nombre',
                    'gaf_diasem.diasem_abreviatura'
                )
                ->get()
                ->keyBy('jorofi_diasem_codigo');

            // Crear calendario del mes
            $primerDia = Carbon::create($anio, $mes, 1);
            $ultimoDia = $primerDia->copy()->endOfMonth();
            $calendario = [];

            // Recorrer todos los días del mes
            for ($fecha = $primerDia->copy(); $fecha->lte($ultimoDia); $fecha->addDay()) {
                $diaSemana = $fecha->dayOfWeekIso; // 1=Lunes, 7=Domingo
                $horarioDia = $horarios->get($diaSemana);
                
                $calendario[] = [
                    'fecha' => $fecha->format('Y-m-d'),
                    'dia' => $fecha->day,
                    'dia_semana' => $diaSemana,
                    'dia_nombre' => $fecha->locale('es')->dayName,
                    'dia_nombre_corto' => $fecha->locale('es')->shortDayName,
                    'tiene_horario' => $horarioDia ? true : false,
                    'horario_activo' => $horarioDia ? ($horarioDia->jorofi_ctrhabil == 1) : false,
                    'hora_inicio' => $horarioDia ? Carbon::parse($horarioDia->jorofi_horinicial)->format('H:i') : null,
                    'hora_fin' => $horarioDia ? Carbon::parse($horarioDia->jorofi_horfinal)->format('H:i') : null,
                    'horario_completo' => $horarioDia ? 
                        Carbon::parse($horarioDia->jorofi_horinicial)->format('H:i') . ' - ' . 
                        Carbon::parse($horarioDia->jorofi_horfinal)->format('H:i') : null,
                    'jornada' => $horarioDia ? 
                        (Carbon::parse($horarioDia->jorofi_horinicial)->hour < 12 ? 'MATUTINA' : 'NOCTURNA') : null,
                    'estado' => $horarioDia ? 
                        ($horarioDia->jorofi_ctrhabil == 1 ? 'OPERATIVO' : 'CERRADO') : 'SIN_HORARIO',
                    'es_hoy' => $fecha->isToday(),
                    'es_fin_semana' => $fecha->isWeekend()
                ];
            }

            // Estadísticas del mes
            $diasOperativos = collect($calendario)->where('horario_activo', true)->count();
            $diasCerrados = collect($calendario)->where('estado', 'CERRADO')->count();
            $diasSinHorario = collect($calendario)->where('estado', 'SIN_HORARIO')->count();

            $stats = [
                'mes' => $mes,
                'anio' => $anio,
                'nombre_mes' => $primerDia->locale('es')->monthName,
                'total_dias' => count($calendario),
                'dias_operativos' => $diasOperativos,
                'dias_cerrados' => $diasCerrados,
                'dias_sin_horario' => $diasSinHorario,
                'porcentaje_operativo' => round(($diasOperativos / count($calendario)) * 100, 1)
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Calendario obtenido correctamente',
                'data' => [
                    'oficina' => $oficina,
                    'calendario' => $calendario,
                    'estadisticas' => $stats,
                    'configuracion_horarios' => $horarios->values()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo calendario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener calendario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Copiar horarios de una oficina a otra
     * POST /api/oficinas/{oficinaOrigenId}/horarios/copiar/{oficinaDestinoId}
     */
    

    /**
     * Obtener estadísticas generales de horarios de todas las oficinas
     * GET /api/horarios/estadisticas
     */
    public function estadisticasGenerales()
    {
        try {
            Log::info("📊 Obteniendo estadísticas generales de horarios");
            
            $stats = [
                'oficinas_totales' => DB::table('gaf_oficin')->count(),
                'oficinas_con_horarios' => DB::table('gaf_oficin')
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('gaf_jorofi')
                            ->whereRaw('gaf_jorofi.jorofi_oficin_codigo = gaf_oficin.oficin_codigo');
                    })
                    ->count(),
                'oficinas_sin_horarios' => DB::table('gaf_oficin')
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('gaf_jorofi')
                            ->whereRaw('gaf_jorofi.jorofi_oficin_codigo = gaf_oficin.oficin_codigo');
                    })
                    ->count(),
                'total_horarios_configurados' => DB::table('gaf_jorofi')->count(),
                'horarios_activos' => DB::table('gaf_jorofi')->where('jorofi_ctrhabil', 1)->count(),
                'horarios_inactivos' => DB::table('gaf_jorofi')->where('jorofi_ctrhabil', 0)->count(),
                'oficinas_con_cobertura_completa' => DB::table('gaf_oficin')
                    ->whereRaw('(SELECT COUNT(*) FROM gaf_jorofi WHERE jorofi_oficin_codigo = gaf_oficin.oficin_codigo AND jorofi_ctrhabil = 1) = 7')
                    ->count()
            ];

            // Distribución por días de la semana
            $distribucionDias = DB::table('gaf_jorofi')
                ->join('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->select(
                    'gaf_diasem.diasem_nombre',
                    'gaf_diasem.diasem_codigo',
                    DB::raw('COUNT(*) as total_oficinas'),
                    DB::raw('SUM(CASE WHEN jorofi_ctrhabil = 1 THEN 1 ELSE 0 END) as oficinas_activas')
                )
                ->groupBy('gaf_diasem.diasem_codigo', 'gaf_diasem.diasem_nombre')
                ->orderBy('gaf_diasem.diasem_codigo')
                ->get();

            // Top oficinas con más horarios configurados
            $topOficinas = DB::table('gaf_oficin')
                ->leftJoin('gaf_jorofi', 'gaf_oficin.oficin_codigo', '=', 'gaf_jorofi.jorofi_oficin_codigo')
                ->leftJoin('gaf_instit', 'gaf_oficin.oficin_instit_codigo', '=', 'gaf_instit.instit_codigo')
                ->select(
                    'gaf_oficin.oficin_codigo',
                    'gaf_oficin.oficin_nombre',
                    'gaf_instit.instit_nombre',
                    DB::raw('COUNT(gaf_jorofi.jorofi_diasem_codigo) as dias_configurados'),
                    DB::raw('SUM(CASE WHEN gaf_jorofi.jorofi_ctrhabil = 1 THEN 1 ELSE 0 END) as dias_activos')
                )
                ->groupBy('gaf_oficin.oficin_codigo', 'gaf_oficin.oficin_nombre', 'gaf_instit.instit_nombre')
                ->orderBy('dias_configurados', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Estadísticas generales obtenidas correctamente',
                'data' => [
                    'resumen' => $stats,
                    'distribucion_por_dias' => $distribucionDias,
                    'top_oficinas' => $topOficinas,
                    'porcentaje_cobertura' => $stats['oficinas_totales'] > 0 ? 
                        round(($stats['oficinas_con_horarios'] / $stats['oficinas_totales']) * 100, 1) : 0
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
     * Validar si una oficina está operativa en un momento específico
     * GET /api/oficinas/{oficinaId}/validar-horario?fecha=YYYY-MM-DD&hora=HH:MM
     */
    public function validarHorario($oficinaId, Request $request)
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

            // Verificar que la oficina existe y está activa
            $oficina = DB::table('gaf_oficin')
                ->where('oficin_codigo', $oficinaId)
                ->where('oficin_ctractual', 1)
                ->first();

            if (!$oficina) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oficina no encontrada o inactiva',
                    'data' => [
                        'puede_acceder' => false,
                        'motivo' => 'OFICINA_INACTIVA'
                    ]
                ], 404);
            }

            // Obtener horario para el día específico
            $horario = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaId)
                ->where('gaf_jorofi.jorofi_diasem_codigo', $diaSemana)
                ->where('gaf_jorofi.jorofi_ctrhabil', 1)
                ->select(
                    'gaf_jorofi.*',
                    'gaf_diasem.diasem_nombre'
                )
                ->first();

            if (!$horario) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Sin horario configurado para este día',
                    'data' => [
                        'puede_acceder' => false,
                        'motivo' => 'SIN_HORARIO',
                        'dia_semana' => $diaSemana,
                        'fecha_consultada' => $request->fecha,
                        'hora_consultada' => $hora
                    ]
                ]);
            }

            // Validar si la hora está dentro del rango permitido
            $horaConsulta = Carbon::createFromFormat('H:i', $hora);
            $horaInicio = Carbon::createFromFormat('H:i', $horario->jorofi_horinicial);
            $horaFin = Carbon::createFromFormat('H:i', $horario->jorofi_horfinal);

            $puedeAcceder = false;
            $dentroDelHorario = false;

            // Verificar si el horario cruza medianoche
            if ($horaFin < $horaInicio) {
                // Horario nocturno que cruza medianoche (ej: 22:00 - 06:00)
                $dentroDelHorario = $horaConsulta >= $horaInicio || $horaConsulta <= $horaFin;
            } else {
                // Horario normal (ej: 08:00 - 18:00)
                $dentroDelHorario = $horaConsulta >= $horaInicio && $horaConsulta <= $horaFin;
            }

            $puedeAcceder = $dentroDelHorario;

            return response()->json([
                'status' => 'success',
                'message' => $puedeAcceder ? 'Acceso permitido' : 'Acceso denegado - fuera de horario',
                'data' => [
                    'puede_acceder' => $puedeAcceder,
                    'motivo' => $puedeAcceder ? 'DENTRO_DE_HORARIO' : 'FUERA_DE_HORARIO',
                    'oficina_id' => $oficinaId,
                    'fecha_consultada' => $request->fecha,
                    'hora_consultada' => $hora,
                    'dia_semana' => $diaSemana,
                    'dia_nombre' => trim($horario->diasem_nombre),
                    'horario_oficina' => [
                        'hora_inicio' => $horario->jorofi_horinicial,
                        'hora_fin' => $horario->jorofi_horfinal,
                        'cruza_medianoche' => $horaFin < $horaInicio,
                        'jornada' => $horaInicio->hour < 12 ? 'MATUTINA' : 'NOCTURNA'
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error validando horario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al validar horario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener horario actual de una oficina (para uso interno del sistema)
     * GET /api/oficinas/{oficinaId}/horario-actual
     */
    public function horarioActual($oficinaId)
    {
        try {
            $now = Carbon::now();
            $diaSemana = $now->dayOfWeekIso; // 1=Lunes, 7=Domingo
            
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

            // Obtener horario para hoy
            $horario = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaId)
                ->where('gaf_jorofi.jorofi_diasem_codigo', $diaSemana)
                ->select(
                    'gaf_jorofi.*',
                    'gaf_diasem.diasem_nombre'
                )
                ->first();

            $response = [
                'oficina_id' => $oficinaId,
                'fecha_actual' => $now->format('Y-m-d'),
                'hora_actual' => $now->format('H:i'),
                'dia_semana' => $diaSemana,
                'tiene_horario_configurado' => $horario ? true : false,
                'horario_activo' => $horario ? ($horario->jorofi_ctrhabil == 1) : false,
                'oficina_operativa' => $oficina->oficin_ctractual == 1
            ];

            if ($horario) {
                $horaInicio = Carbon::createFromFormat('H:i', $horario->jorofi_horinicial);
                $horaFin = Carbon::createFromFormat('H:i', $horario->jorofi_horfinal);
                $horaActual = Carbon::createFromFormat('H:i', $now->format('H:i'));
                
                // Verificar si está dentro del horario
                $dentroDelHorario = false;
                if ($horaFin < $horaInicio) {
                    // Horario nocturno que cruza medianoche
                    $dentroDelHorario = $horaActual >= $horaInicio || $horaActual <= $horaFin;
                } else {
                    // Horario normal
                    $dentroDelHorario = $horaActual >= $horaInicio && $horaActual <= $horaFin;
                }

                // Calcular tiempo restante hasta el cierre
                $tiempoRestante = null;
                if ($dentroDelHorario && $horario->jorofi_ctrhabil == 1) {
                    if ($horaFin < $horaInicio) {
                        // Horario nocturno
                        if ($horaActual >= $horaInicio) {
                            $tiempoRestante = $horaActual->diffInMinutes($horaFin->addDay());
                        } else {
                            $tiempoRestante = $horaActual->diffInMinutes($horaFin);
                        }
                    } else {
                        // Horario normal
                        $tiempoRestante = $horaActual->diffInMinutes($horaFin);
                    }
                }

                $response['horario_detalle'] = [
                    'dia_nombre' => trim($horario->diasem_nombre),
                    'hora_inicio' => $horario->jorofi_horinicial,
                    'hora_fin' => $horario->jorofi_horfinal,
                    'activo' => $horario->jorofi_ctrhabil == 1,
                    'cruza_medianoche' => $horaFin < $horaInicio,
                    'jornada' => $horaInicio->hour < 12 ? 'MATUTINA' : 'NOCTURNA',
                    'dentro_de_horario' => $dentroDelHorario,
                    'tiempo_restante_minutos' => $tiempoRestante,
                    'alerta_cierre_proximo' => $tiempoRestante && $tiempoRestante <= 1
                ];

                $response['puede_acceder'] = $response['oficina_operativa'] && 
                                           $response['horario_activo'] && 
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
            Log::error("❌ Error obteniendo horario actual: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener horario actual: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

   public function proximosHorarios($oficinaId, Request $request)
    {
        try {
            $diasAMostrar = $request->get('dias', 7);
            if ($diasAMostrar > 30) $diasAMostrar = 30; // Máximo 30 días
            
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

            // Obtener todos los horarios configurados
            $horariosConfigurados = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaId)
                ->select(
                    'gaf_jorofi.*',
                    'gaf_diasem.diasem_nombre'
                )
                ->get()
                ->keyBy('jorofi_diasem_codigo');

            $proximosHorarios = [];
            $fechaInicio = Carbon::now();

            for ($i = 0; $i < $diasAMostrar; $i++) {
                $fecha = $fechaInicio->copy()->addDays($i);
                $diaSemana = $fecha->dayOfWeekIso;
                $horario = $horariosConfigurados->get($diaSemana);

                $diaInfo = [
                    'fecha' => $fecha->format('Y-m-d'),
                    'dia_semana' => $diaSemana,
                    'dia_nombre' => $fecha->locale('es')->dayName,
                    'es_hoy' => $fecha->isToday(),
                    'es_manana' => $fecha->isTomorrow(),
                    'es_fin_semana' => $fecha->isWeekend(),
                    'tiene_horario' => $horario ? true : false,
                    'oficina_operativa' => $horario ? ($horario->jorofi_ctrhabil == 1) : false
                ];

                if ($horario) {
                    $diaInfo['horario'] = [
                        'hora_inicio' => $horario->jorofi_horinicial,
                        'hora_fin' => $horario->jorofi_horfinal,
                        'formato_visual' => Carbon::parse($horario->jorofi_horinicial)->format('H:i') . ' - ' . 
                                          Carbon::parse($horario->jorofi_horfinal)->format('H:i'),
                        'jornada' => Carbon::parse($horario->jorofi_horinicial)->hour < 12 ? 'MATUTINA' : 'NOCTURNA',
                        'activo' => $horario->jorofi_ctrhabil == 1,
                        'cruza_medianoche' => Carbon::parse($horario->jorofi_horfinal) < Carbon::parse($horario->jorofi_horinicial)
                    ];
                } else {
                    $diaInfo['horario'] = null;
                }

                $proximosHorarios[] = $diaInfo;
            }

            // Estadísticas de los próximos días
            $diasOperativos = collect($proximosHorarios)->where('oficina_operativa', true)->count();
            $diasCerrados = collect($proximosHorarios)->where('tiene_horario', false)->count();

            return response()->json([
                'status' => 'success',
                'message' => 'Próximos horarios obtenidos correctamente',
                'data' => [
                    'oficina' => [
                        'oficin_codigo' => $oficina->oficin_codigo,
                        'oficin_nombre' => $oficina->oficin_nombre,
                        'activa' => $oficina->oficin_ctractual == 1
                    ],
                    'proximos_horarios' => $proximosHorarios,
                    'resumen' => [
                        'total_dias' => $diasAMostrar,
                        'dias_operativos' => $diasOperativos,
                        'dias_cerrados' => $diasCerrados,
                        'porcentaje_operativo' => round(($diasOperativos / $diasAMostrar) * 100, 1)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo próximos horarios: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener próximos horarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Activar/desactivar horario de un día específico
     * PUT /api/oficinas/{oficinaId}/horarios/{diaId}/toggle
     */
    public function toggleHorario($oficinaId, $diaId)
    {
        try {
            Log::info("🔄 Cambiando estado de horario oficina {$oficinaId}, día {$diaId}");
            
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

            // Verificar que el horario existe
            $horario = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaId)
                ->where('gaf_jorofi.jorofi_diasem_codigo', $diaId)
                ->select('gaf_jorofi.*', 'gaf_diasem.diasem_nombre')
                ->first();

            if (!$horario) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No existe horario configurado para este día',
                    'data' => null
                ], 404);
            }

            DB::beginTransaction();

            // Cambiar estado
            $nuevoEstado = $horario->jorofi_ctrhabil == 1 ? 0 : 1;
            
            DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaId)
                ->where('jorofi_diasem_codigo', $diaId)
                ->update(['jorofi_ctrhabil' => $nuevoEstado]);

            DB::commit();

            $resultado = [
                'oficina_id' => $oficinaId,
                'dia_codigo' => $diaId,
                'dia_nombre' => trim($horario->diasem_nombre),
                'estado_anterior' => $horario->jorofi_ctrhabil == 1 ? 'ACTIVO' : 'INACTIVO',
                'estado_nuevo' => $nuevoEstado == 1 ? 'ACTIVO' : 'INACTIVO',
                'horario' => $horario->jorofi_horinicial . ' - ' . $horario->jorofi_horfinal
            ];

            Log::info("✅ Estado de horario cambiado:", $resultado);

            return response()->json([
                'status' => 'success',
                'message' => "Horario {$resultado['estado_nuevo']} para " . trim($horario->diasem_nombre),
                'data' => $resultado
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error cambiando estado de horario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar estado del horario: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener plantillas de horarios predefinidas
     * GET /api/horarios/plantillas
     */
    public function plantillasHorarios()
    {
        try {
            $plantillas = [
                [
                    'id' => 'oficina_normal',
                    'nombre' => 'Oficina Normal (Lun-Vie)',
                    'descripcion' => 'Horario estándar de oficina, lunes a viernes',
                    'horarios' => [
                        ['dia_codigo' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 3, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 4, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 5, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true]
                    ]
                ],
                [
                    'id' => 'oficina_sabados',
                    'nombre' => 'Oficina con Sábados',
                    'descripcion' => 'Lunes a viernes completo, sábados medio día',
                    'horarios' => [
                        ['dia_codigo' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 3, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 4, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 5, 'hora_inicio' => '08:00', 'hora_fin' => '17:00', 'activo' => true],
                        ['dia_codigo' => 6, 'hora_inicio' => '09:00', 'hora_fin' => '13:00', 'activo' => true]
                    ]
                ],
                [
                    'id' => 'jornada_extendida',
                    'nombre' => 'Jornada Extendida',
                    'descripcion' => 'Horario extendido lunes a sábado',
                    'horarios' => [
                        ['dia_codigo' => 1, 'hora_inicio' => '07:00', 'hora_fin' => '18:00', 'activo' => true],
                        ['dia_codigo' => 2, 'hora_inicio' => '07:00', 'hora_fin' => '18:00', 'activo' => true],
                        ['dia_codigo' => 3, 'hora_inicio' => '07:00', 'hora_fin' => '18:00', 'activo' => true],
                        ['dia_codigo' => 4, 'hora_inicio' => '07:00', 'hora_fin' => '18:00', 'activo' => true],
                        ['dia_codigo' => 5, 'hora_inicio' => '07:00', 'hora_fin' => '18:00', 'activo' => true],
                        ['dia_codigo' => 6, 'hora_inicio' => '08:00', 'hora_fin' => '14:00', 'activo' => true]
                    ]
                ],
                [
                    'id' => 'turno_nocturno',
                    'nombre' => 'Turno Nocturno',
                    'descripcion' => 'Horario nocturno que cruza medianoche',
                    'horarios' => [
                        ['dia_codigo' => 1, 'hora_inicio' => '22:00', 'hora_fin' => '06:00', 'activo' => true],
                        ['dia_codigo' => 2, 'hora_inicio' => '22:00', 'hora_fin' => '06:00', 'activo' => true],
                        ['dia_codigo' => 3, 'hora_inicio' => '22:00', 'hora_fin' => '06:00', 'activo' => true],
                        ['dia_codigo' => 4, 'hora_inicio' => '22:00', 'hora_fin' => '06:00', 'activo' => true],
                        ['dia_codigo' => 5, 'hora_inicio' => '22:00', 'hora_fin' => '06:00', 'activo' => true]
                    ]
                ],
                [
                    'id' => 'disponibilidad_24_7',
                    'nombre' => 'Disponibilidad 24/7',
                    'descripcion' => 'Acceso las 24 horas, todos los días',
                    'horarios' => [
                        ['dia_codigo' => 1, 'hora_inicio' => '00:00', 'hora_fin' => '23:59', 'activo' => true],
                        ['dia_codigo' => 2, 'hora_inicio' => '00:00', 'hora_fin' => '23:59', 'activo' => true],
                        ['dia_codigo' => 3, 'hora_inicio' => '00:00', 'hora_fin' => '23:59', 'activo' => true],
                        ['dia_codigo' => 4, 'hora_inicio' => '00:00', 'hora_fin' => '23:59', 'activo' => true],
                        ['dia_codigo' => 5, 'hora_inicio' => '00:00', 'hora_fin' => '23:59', 'activo' => true],
                        ['dia_codigo' => 6, 'hora_inicio' => '00:00', 'hora_fin' => '23:59', 'activo' => true],
                        ['dia_codigo' => 7, 'hora_inicio' => '00:00', 'hora_fin' => '23:59', 'activo' => true]
                    ]
                ]
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Plantillas de horarios obtenidas correctamente',
                'data' => $plantillas
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo plantillas: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener plantillas: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Aplicar plantilla de horarios a una oficina
     * POST /api/oficinas/{oficinaId}/horarios/aplicar-plantilla
     */
    public function aplicarPlantilla($oficinaId, Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'plantilla_id' => 'required|string|in:oficina_normal,oficina_sabados,jornada_extendida,turno_nocturno,disponibilidad_24_7',
                'sobrescribir_existentes' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Obtener plantillas disponibles
            $plantillasResponse = $this->plantillasHorarios();
            $plantillas = collect($plantillasResponse->getData()->data);
            
            $plantilla = $plantillas->where('id', $request->plantilla_id)->first();
            
            if (!$plantilla) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plantilla no encontrada',
                    'data' => null
                ], 404);
            }

            // Aplicar la plantilla usando el método storeBatch
            $batchRequest = new Request([
                'horarios' => $plantilla->horarios,
                'sobrescribir_existentes' => $request->get('sobrescribir_existentes', true)
            ]);

            $resultado = $this->storeBatch($oficinaId, $batchRequest);
            
            // Modificar la respuesta para incluir información de la plantilla
            if ($resultado->getStatusCode() === 200) {
                $data = $resultado->getData();
                $data->message = "Plantilla '{$plantilla->nombre}' aplicada exitosamente";
                $data->data->plantilla_aplicada = [
                    'id' => $plantilla->id,
                    'nombre' => $plantilla->nombre,
                    'descripcion' => $plantilla->descripcion
                ];
            }

            return $resultado;

        } catch (\Exception $e) {
            Log::error("❌ Error aplicando plantilla: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al aplicar plantilla: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Verificar conflictos de horarios en una oficina
     * GET /api/oficinas/{oficinaId}/verificar-conflictos
     */
    public function verificarConflictos($oficinaId)
    {
        try {
            Log::info("🔍 Verificando conflictos de horarios para oficina {$oficinaId}");
            
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

            // Obtener todos los horarios de la oficina
            $horarios = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaId)
                ->select(
                    'gaf_jorofi.*',
                    'gaf_diasem.diasem_nombre'
                )
                ->orderBy('gaf_jorofi.jorofi_diasem_codigo')
                ->get();

            $conflictos = [];
            $advertencias = [];
            $recomendaciones = [];

            foreach ($horarios as $horario) {
                $horaInicio = Carbon::createFromFormat('H:i', $horario->jorofi_horinicial);
                $horaFin = Carbon::createFromFormat('H:i', $horario->jorofi_horfinal);
                
                // Verificar horarios que cruzan medianoche
                if ($horaFin < $horaInicio) {
                    $advertencias[] = [
                        'tipo' => 'HORARIO_NOCTURNO',
                        'dia' => trim($horario->diasem_nombre),
                        'mensaje' => "Horario nocturno detectado: {$horario->jorofi_horinicial} - {$horario->jorofi_horfinal}",
                        'recomendacion' => 'Verificar que este horario nocturno es correcto'
                    ];
                }
                
                // Verificar horarios muy cortos (menos de 2 horas)
                $duracion = $horaFin < $horaInicio ? 
                    (24 - $horaInicio->hour) + $horaFin->hour : 
                    $horaFin->diffInHours($horaInicio);
                
                if ($duracion < 2) {
                    $advertencias[] = [
                        'tipo' => 'HORARIO_CORTO',
                        'dia' => trim($horario->diasem_nombre),
                        'mensaje' => "Horario muy corto detectado: {$duracion} horas",
                        'recomendacion' => 'Considerar extender el horario para mayor disponibilidad'
                    ];
                }
                
                // Verificar horarios muy largos (más de 16 horas)
                if ($duracion > 16) {
                    $advertencias[] = [
                        'tipo' => 'HORARIO_LARGO',
                        'dia' => trim($horario->diasem_nombre),
                        'mensaje' => "Horario muy extenso detectado: {$duracion} horas",
                        'recomendacion' => 'Verificar si este horario extenso es necesario'
                    ];
                }
                
                // Verificar horarios inactivos
                if ($horario->jorofi_ctrhabil == 0) {
                    $advertencias[] = [
                        'tipo' => 'HORARIO_INACTIVO',
                        'dia' => trim($horario->diasem_nombre),
                        'mensaje' => "Horario configurado pero inactivo",
                        'recomendacion' => 'Activar el horario si es necesario'
                    ];
                }
            }

            // Verificar días sin horario configurado
            $diasConfigurados = $horarios->pluck('jorofi_diasem_codigo')->toArray();
            $todosLosDias = [1, 2, 3, 4, 5, 6, 7]; // Lunes a Domingo
            $diasSinHorario = array_diff($todosLosDias, $diasConfigurados);
            
            if (!empty($diasSinHorario)) {
                $nombresDias = DB::table('gaf_diasem')
                    ->whereIn('diasem_codigo', $diasSinHorario)
                    ->pluck('diasem_nombre')
                    ->toArray();
                
                $recomendaciones[] = [
                    'tipo' => 'DIAS_SIN_HORARIO',
                    'mensaje' => "Días sin horario configurado: " . implode(', ', $nombresDias),
                    'recomendacion' => 'Configurar horarios para estos días si es necesario'
                ];
            }

            // Verificar cobertura de fin de semana
            $tieneSabado = in_array(6, $diasConfigurados);
            $tieneDomingo = in_array(7, $diasConfigurados);
            
            if (!$tieneSabado && !$tieneDomingo) {
                $recomendaciones[] = [
                    'tipo' => 'SIN_COBERTURA_FIN_SEMANA',
                    'mensaje' => 'No hay cobertura de fin de semana',
                    'recomendacion' => 'Considerar agregar horarios para sábado o domingo'
                ];
            }

            // Resumen de análisis
            $resumen = [
                'total_horarios_configurados' => $horarios->count(),
                'horarios_activos' => $horarios->where('jorofi_ctrhabil', 1)->count(),
                'horarios_inactivos' => $horarios->where('jorofi_ctrhabil', 0)->count(),
                'dias_sin_configurar' => count($diasSinHorario),
                'tiene_cobertura_fin_semana' => $tieneSabado || $tieneDomingo,
                'total_conflictos' => count($conflictos),
                'total_advertencias' => count($advertencias),
                'total_recomendaciones' => count($recomendaciones)
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Verificación de conflictos completada',
                'data' => [
                    'oficina_id' => $oficinaId,
                    'resumen' => $resumen,
                    'conflictos' => $conflictos,
                    'advertencias' => $advertencias,
                    'recomendaciones' => $recomendaciones,
                    'estado_general' => count($conflictos) === 0 ? 'SIN_CONFLICTOS' : 'CON_CONFLICTOS',
                    'nivel_riesgo' => count($conflictos) > 0 ? 'ALTO' : 
                                   (count($advertencias) > 3 ? 'MEDIO' : 'BAJO')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error verificando conflictos: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al verificar conflictos: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener reporte completo de horarios de una oficina
     * GET /api/oficinas/{oficinaId}/reporte-horarios
     */
    public function reporteHorarios($oficinaId, Request $request)
    {
        try {
            Log::info("📊 Generando reporte de horarios para oficina {$oficinaId}");
            
            // Obtener información básica usando métodos existentes
            $horarios = $this->index($oficinaId, $request);
            $calendario = $this->calendario($oficinaId, $request);
            $conflictos = $this->verificarConflictos($oficinaId);
            $proximosHorarios = $this->proximosHorarios($oficinaId, $request);

            // Verificar que todos los métodos retornaron datos correctamente
            if ($horarios->getStatusCode() !== 200) {
                return $horarios;
            }

            $horariosData = $horarios->getData()->data;
            $calendarioData = $calendario->getStatusCode() === 200 ? $calendario->getData()->data : null;
            $conflictosData = $conflictos->getStatusCode() === 200 ? $conflictos->getData()->data : null;
            $proximosData = $proximosHorarios->getStatusCode() === 200 ? $proximosHorarios->getData()->data : null;

            // Calcular métricas adicionales
            $metricas = [
                'porcentaje_cobertura_semanal' => ($horariosData->estadisticas->dias_activos / 7) * 100,
                'promedio_horas_por_dia' => 0,
                'jornada_predominante' => null,
                'dia_mas_extenso' => null,
                'dia_menos_extenso' => null
            ];

            // Calcular promedio de horas por día y otras métricas
            $totalHoras = 0;
            $diasConHorario = 0;
            $horasMatutinas = 0;
            $horasNocturnas = 0;
            $duracionesPorDia = [];

            foreach ($horariosData->horarios_por_dia as $dia) {
                if ($dia->tiene_horario && $dia->activo) {
                    $horaInicio = Carbon::createFromFormat('H:i', $dia->hora_inicio);
                    $horaFin = Carbon::createFromFormat('H:i', $dia->hora_fin);
                    
                    $duracion = $horaFin < $horaInicio ? 
                        (24 - $horaInicio->hour) + $horaFin->hour : 
                        $horaFin->diffInHours($horaInicio);
                    
                    $totalHoras += $duracion;
                    $diasConHorario++;
                    
                    $duracionesPorDia[] = [
                        'dia' => $dia->dia_nombre,
                        'duracion' => $duracion
                    ];
                    
                    if ($dia->jornada === 'MATUTINA') {
                        $horasMatutinas += $duracion;
                    } else {
                        $horasNocturnas += $duracion;
                    }
                }
            }

            if ($diasConHorario > 0) {
                $metricas['promedio_horas_por_dia'] = round($totalHoras / $diasConHorario, 2);
                $metricas['jornada_predominante'] = $horasMatutinas > $horasNocturnas ? 'MATUTINA' : 'NOCTURNA';
                
                // Encontrar día más y menos extenso
                $duracionesPorDia = collect($duracionesPorDia);
                $metricas['dia_mas_extenso'] = $duracionesPorDia->sortByDesc('duracion')->first();
                $metricas['dia_menos_extenso'] = $duracionesPorDia->sortBy('duracion')->first();
            }

            $reporte = [
                'fecha_generacion' => Carbon::now()->format('Y-m-d H:i:s'),
                'oficina' => $horariosData->oficina,
                'resumen_ejecutivo' => [
                    'estado_configuracion' => $horariosData->configuracion_completa ? 'COMPLETA' : 'INCOMPLETA',
                    'nivel_operatividad' => $metricas['porcentaje_cobertura_semanal'] >= 80 ? 'ALTO' : 
                                          ($metricas['porcentaje_cobertura_semanal'] >= 50 ? 'MEDIO' : 'BAJO'),
                    'jornada_predominante' => $metricas['jornada_predominante'],
                    'promedio_horas_diarias' => $metricas['promedio_horas_por_dia'],
                    'tiene_conflictos' => $conflictosData ? $conflictosData->estado_general === 'CON_CONFLICTOS' : false
                ],
                'metricas_detalladas' => $metricas,
                'horarios_configurados' => $horariosData->horarios_por_dia,
                'estadisticas' => $horariosData->estadisticas,
                'calendario_actual' => $calendarioData ? $calendarioData->calendario : null,
                'proximos_7_dias' => $proximosData ? $proximosData->proximos_horarios : null,
                'analisis_conflictos' => $conflictosData,
                'recomendaciones_mejora' => [
                    'configuracion' => [],
                    'operativas' => [],
                    'seguridad' => []
                ]
            ];

            // Generar recomendaciones automáticas
            if ($metricas['porcentaje_cobertura_semanal'] < 70) {
                $reporte['recomendaciones_mejora']['configuracion'][] = 
                    'Considerar ampliar la cobertura semanal configurando más días';
            }

            if ($metricas['promedio_horas_por_dia'] < 6) {
                $reporte['recomendaciones_mejora']['operativas'][] = 
                    'Los horarios promedio son cortos, evaluar si son suficientes para las operaciones';
            }

            if ($horariosData->estadisticas->dias_inactivos > 0) {
                $reporte['recomendaciones_mejora']['configuracion'][] = 
                    'Revisar días con horarios configurados pero inactivos';
            }

            if (!$horariosData->estadisticas->oficina_operativa) {
                $reporte['recomendaciones_mejora']['seguridad'][] = 
                    'La oficina tiene configuración de horarios pero no está marcada como operativa';
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Reporte de horarios generado correctamente',
                'data' => $reporte
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error generando reporte: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar reporte: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Validación pública de horario (sin autenticación)
     * GET /api/oficinas/{oficinaId}/horario-publico
     */
    public function validacionPublica($oficinaId)
    {
        try {
            $now = Carbon::now();
            $diaSemana = $now->dayOfWeekIso; // 1=Lunes, 7=Domingo
            
            // Verificar que la oficina existe y está activa
            $oficina = DB::table('gaf_oficin')
                ->where('oficin_codigo', $oficinaId)
                ->where('oficin_ctractual', 1)
                ->first();

            if (!$oficina) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oficina no encontrada o inactiva',
                    'data' => [
                        'puede_acceder' => false,
                        'motivo' => 'OFICINA_INACTIVA'
                    ]
                ]);
            }

            // Obtener horario para hoy
            $horario = DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaId)
                ->where('jorofi_diasem_codigo', $diaSemana)
                ->where('jorofi_ctrhabil', 1)
                ->first();

            if (!$horario) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Sin horario para hoy',
                    'data' => [
                        'puede_acceder' => false,
                        'motivo' => 'SIN_HORARIO',
                        'dia_actual' => $diaSemana,
                        'fecha_actual' => $now->format('Y-m-d'),
                        'hora_actual' => $now->format('H:i')
                    ]
                ]);
            }

            // Validar si está dentro del horario
            $horaActual = $now->format('H:i');
            $horaInicio = Carbon::createFromFormat('H:i', $horario->jorofi_horinicial);
            $horaFin = Carbon::createFromFormat('H:i', $horario->jorofi_horfinal);
            $horaConsulta = Carbon::createFromFormat('H:i', $horaActual);

            $dentroDelHorario = false;
            
            // Verificar si el horario cruza medianoche
            if ($horaFin < $horaInicio) {
                $dentroDelHorario = $horaConsulta >= $horaInicio || $horaConsulta <= $horaFin;
            } else {
                $dentroDelHorario = $horaConsulta >= $horaInicio && $horaConsulta <= $horaFin;
            }

            // Calcular tiempo restante hasta el cierre
            $tiempoRestante = null;
            if ($dentroDelHorario) {
                if ($horaFin < $horaInicio) {
                    // Horario nocturno
                    if ($horaConsulta >= $horaInicio) {
                        // Estamos después de la hora de inicio, el cierre es al día siguiente
                        $tiempoRestante = $horaConsulta->diffInMinutes($horaFin->addDay());
                    } else {
                        // Estamos antes del cierre del mismo día
                        $tiempoRestante = $horaConsulta->diffInMinutes($horaFin);
                    }
                } else {
                    // Horario normal
                    $tiempoRestante = $horaConsulta->diffInMinutes($horaFin);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => $dentroDelHorario ? 'Dentro del horario' : 'Fuera del horario',
                'data' => [
                    'puede_acceder' => $dentroDelHorario,
                    'motivo' => $dentroDelHorario ? 'DENTRO_DE_HORARIO' : 'FUERA_DE_HORARIO',
                    'oficina_id' => $oficinaId,
                    'fecha_actual' => $now->format('Y-m-d'),
                    'hora_actual' => $horaActual,
                    'dia_semana' => $diaSemana,
                    'horario_oficina' => [
                        'hora_inicio' => $horario->jorofi_horinicial,
                        'hora_fin' => $horario->jorofi_horfinal,
                        'cruza_medianoche' => $horaFin < $horaInicio
                    ],
                    'tiempo_restante_minutos' => $tiempoRestante,
                    'alerta_cierre_proximo' => $tiempoRestante && $tiempoRestante <= 1 // 1 minuto o menos
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al validar horario: ' . $e->getMessage(),
                'data' => [
                    'puede_acceder' => false,
                    'motivo' => 'ERROR_SISTEMA'
                ]
            ], 500);
        }
    }
    /**
     * Eliminar todos los horarios de una oficina
     * DELETE /api/oficinas/{oficinaId}/horarios
     */
    public function destroyAll($oficinaId)
    {
        try {
            Log::info("🗑️ Eliminando todos los horarios de oficina {$oficinaId}");
            
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

            // Contar horarios existentes
            $cantidadHorarios = DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaId)
                ->count();

            if ($cantidadHorarios === 0) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'No hay horarios configurados para eliminar',
                    'data' => [
                        'oficina_id' => $oficinaId,
                        'horarios_eliminados' => 0
                    ]
                ]);
            }

            DB::beginTransaction();

            DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaId)
                ->delete();

            DB::commit();

            Log::info("✅ Todos los horarios eliminados exitosamente:", [
                'oficina_id' => $oficinaId,
                'cantidad_eliminada' => $cantidadHorarios
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Se eliminaron {$cantidadHorarios} horarios de la oficina",
                'data' => [
                    'oficina_id' => $oficinaId,
                    'horarios_eliminados' => $cantidadHorarios
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error eliminando todos los horarios: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar horarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Obtener resumen rápido de horarios de una oficina
     * GET /api/oficinas/{oficinaId}/resumen-horarios
     */
    public function resumenHorarios($oficinaId)
    {
        try {
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

            // Obtener horarios configurados
            $horarios = DB::table('gaf_jorofi')
                ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                ->where('gaf_jorofi.jorofi_oficin_codigo', $oficinaId)
                ->select(
                    'gaf_jorofi.jorofi_diasem_codigo',
                    'gaf_jorofi.jorofi_horinicial',
                    'gaf_jorofi.jorofi_horfinal',
                    'gaf_jorofi.jorofi_ctrhabil',
                    'gaf_diasem.diasem_abreviatura'
                )
                ->orderBy('gaf_jorofi.jorofi_diasem_codigo')
                ->get();

            $resumen = [
                'oficina_id' => $oficinaId,
                'oficina_nombre' => $oficina->oficin_nombre,
                'total_dias_configurados' => $horarios->count(),
                'dias_activos' => $horarios->where('jorofi_ctrhabil', 1)->count(),
                'horarios_resumidos' => [],
                'estado_general' => 'SIN_CONFIGURAR'
            ];

            // Crear resumen por día
            foreach ($horarios as $horario) {
                $resumen['horarios_resumidos'][] = [
                    'dia' => trim($horario->diasem_abreviatura),
                    'horario' => $horario->jorofi_horinicial . '-' . $horario->jorofi_horfinal,
                    'activo' => $horario->jorofi_ctrhabil == 1
                ];
            }

            // Determinar estado general
            if ($resumen['total_dias_configurados'] === 0) {
                $resumen['estado_general'] = 'SIN_CONFIGURAR';
            } elseif ($resumen['dias_activos'] === 0) {
                $resumen['estado_general'] = 'CONFIGURADO_INACTIVO';
            } elseif ($resumen['dias_activos'] >= 5) {
                $resumen['estado_general'] = 'OPERATIVO_COMPLETO';
            } else {
                $resumen['estado_general'] = 'OPERATIVO_PARCIAL';
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Resumen de horarios obtenido correctamente',
                'data' => $resumen
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo resumen: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener resumen: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Clonar horarios con modificaciones
     * POST /api/oficinas/{oficinaId}/horarios/clonar
     */
    public function clonarHorarios($oficinaId, Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ajuste_minutos' => 'nullable|integer|between:-120,120',
                'cambiar_activos' => 'nullable|boolean',
                'dias_especificos' => 'nullable|array',
                'dias_especificos.*' => 'integer|between:1,7'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                    'data' => null
                ], 422);
            }

            // Obtener horarios existentes
            $query = DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $oficinaId);

            if ($request->has('dias_especificos')) {
                $query->whereIn('jorofi_diasem_codigo', $request->dias_especificos);
            }

            $horariosExistentes = $query->get();

            if ($horariosExistentes->isEmpty()) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'No hay horarios para clonar',
                    'data' => null
                ]);
            }

            DB::beginTransaction();

            $ajusteMinutos = $request->get('ajuste_minutos', 0);
            $cambiarActivos = $request->get('cambiar_activos');
            $horariosClonados = [];

            foreach ($horariosExistentes as $horario) {
                $horaInicio = Carbon::createFromFormat('H:i', $horario->jorofi_horinicial);
                $horaFin = Carbon::createFromFormat('H:i', $horario->jorofi_horfinal);

                // Aplicar ajuste de minutos
                if ($ajusteMinutos !== 0) {
                    $horaInicio->addMinutes($ajusteMinutos);
                    $horaFin->addMinutes($ajusteMinutos);
                }

                $horarioClonado = [
                    'jorofi_oficin_codigo' => $oficinaId,
                    'jorofi_diasem_codigo' => $horario->jorofi_diasem_codigo,
                    'jorofi_horinicial' => $horaInicio->format('H:i'),
                    'jorofi_horfinal' => $horaFin->format('H:i'),
                    'jorofi_ctrhabil' => $cambiarActivos !== null ? ($cambiarActivos ? 1 : 0) : $horario->jorofi_ctrhabil
                ];

                // Actualizar el horario existente
                DB::table('gaf_jorofi')
                    ->where('jorofi_oficin_codigo', $oficinaId)
                    ->where('jorofi_diasem_codigo', $horario->jorofi_diasem_codigo)
                    ->update($horarioClonado);

                $horariosClonados[] = $horarioClonado;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Se clonaron/modificaron {$horariosExistentes->count()} horarios",
                'data' => [
                    'oficina_id' => $oficinaId,
                    'horarios_modificados' => count($horariosClonados),
                    'ajuste_aplicado' => $ajusteMinutos,
                    'estado_cambiado' => $cambiarActivos
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error clonando horarios: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al clonar horarios: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
}