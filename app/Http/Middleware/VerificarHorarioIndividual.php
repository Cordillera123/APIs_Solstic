<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VerificarHorarioIndividual
{
    /**
     * Manejar solicitud entrante con validación de horario individual
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            // Solo aplicar a usuarios autenticados
            if (!Auth::check()) {
                return $next($request);
            }

            $user = Auth::user();

            // ✅ EXCEPCIONES: Rutas que NO requieren validación de horario
            if ($this->shouldSkipValidation($request, $user)) {
                return $next($request);
            }

            // Obtener usuario completo con manejo de errores
            $usuario = $this->getUsuarioCompleto($user->usu_id);

            if (!$usuario) {
                Log::error("🚫 Usuario no encontrado en middleware: {$user->usu_id}");
                return $this->denyAccess('Usuario no encontrado', 'USER_NOT_FOUND');
            }

            // Verificar si el usuario está deshabilitado
            if ($this->usuarioEstaDeshabilitado($usuario)) {
                Log::warning("🚫 Usuario deshabilitado intentando acceso: {$user->usu_id}");
                return $this->denyAccess('Su cuenta ha sido desactivada', 'USER_DISABLED');
            }

            // ✅ VALIDACIÓN CON HORARIOS INDIVIDUALES
            $validacion = $this->validarHorarioIndividualMiddleware($usuario);

            if (!$validacion['puede_acceder']) {
                // Registrar intento fallido
                $this->registrarIntentoFallido($usuario, $validacion, $request);
                
                Log::warning("🚫 Acceso denegado por horario: {$user->usu_id} - {$validacion['motivo']}");
                
                return $this->denyAccess($validacion['mensaje'], $validacion['motivo'], $validacion);
            }

            // ✅ AGREGAR INFO DE HORARIO AL REQUEST
            $request->merge([
                'horario_info' => $validacion,
                'origen_horario' => $validacion['origen_horario'] ?? 'DESCONOCIDO'
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error("❌ Error en middleware de horario: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // En caso de error, permitir acceso pero loguear
            return $next($request);
        }
    }

    /**
     * ✅ OBTENER USUARIO COMPLETO CON MANEJO DE ERRORES
     */
    private function getUsuarioCompleto($usuarioId)
    {
        try {
            return DB::table('tbl_usu')
                ->where('usu_id', $usuarioId)
                ->first();
        } catch (\Exception $e) {
            Log::error("Error obteniendo usuario: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ VERIFICAR SI USUARIO ESTÁ DESHABILITADO
     */
    private function usuarioEstaDeshabilitado($usuario)
    {
        return $usuario->usu_deshabilitado === true || 
               $usuario->usu_deshabilitado === 1 || 
               $usuario->est_id != 1;
    }

    /**
     * ✅ VALIDACIÓN PRINCIPAL CON HORARIOS INDIVIDUALES
     */
    private function validarHorarioIndividualMiddleware($usuario)
    {
        try {
            // Super Admins (per_id = 3) no tienen restricciones
            if ($usuario->per_id == 3) {
                return [
                    'puede_acceder' => true,
                    'motivo' => 'SUPER_ADMIN',
                    'mensaje' => 'Acceso sin restricciones',
                    'origen_horario' => 'SUPER_ADMIN'
                ];
            }

            if (!$usuario->oficin_codigo) {
                return [
                    'puede_acceder' => false,
                    'motivo' => 'SIN_OFICINA',
                    'mensaje' => 'No tiene oficina asignada. Contacte al administrador.',
                    'origen_horario' => 'NINGUNO'
                ];
            }

            $now = Carbon::now('America/Guayaquil');
            $diaSemana = $now->dayOfWeekIso;
            $horaActual = $now->format('H:i');

            // ✅ LÓGICA DE PRIORIDAD CON TEMPORALES
            $horarioEfectivo = $this->obtenerHorarioEfectivo($usuario, $diaSemana, $now);

            if (!$horarioEfectivo['horario']) {
                return [
                    'puede_acceder' => false,
                    'motivo' => 'SIN_HORARIO',
                    'mensaje' => 'No hay horario configurado para hoy',
                    'origen_horario' => $horarioEfectivo['origen'],
                    'detalles' => [
                        'dia_semana' => $diaSemana,
                        'hora_actual' => $horaActual
                    ]
                ];
            }

            // Validar si está dentro del horario
            $dentroDelHorario = $this->estaDentroDelHorario(
                $horaActual,
                $horarioEfectivo['horario']['hora_entrada'],
                $horarioEfectivo['horario']['hora_salida']
            );

            if (!$dentroDelHorario) {
                $motivoEspecifico = $this->determinarMotivoFallo($horarioEfectivo['origen']);

                return [
                    'puede_acceder' => false,
                    'motivo' => $motivoEspecifico,
                    'mensaje' => "Fuera del horario {$horarioEfectivo['origen']}. Horario: {$horarioEfectivo['horario']['hora_entrada']} - {$horarioEfectivo['horario']['hora_salida']}",
                    'origen_horario' => $horarioEfectivo['origen'],
                    'detalles' => array_merge([
                        'hora_actual' => $horaActual,
                        'horario_entrada' => $horarioEfectivo['horario']['hora_entrada'],
                        'horario_salida' => $horarioEfectivo['horario']['hora_salida']
                    ], $horarioEfectivo['info_adicional'])
                ];
            }

            // ✅ ACCESO PERMITIDO
            return [
                'puede_acceder' => true,
                'motivo' => 'DENTRO_HORARIO',
                'mensaje' => "Acceso permitido con horario {$horarioEfectivo['origen']}",
                'origen_horario' => $horarioEfectivo['origen'],
                'horario_efectivo' => $horarioEfectivo['horario'],
                'detalles' => array_merge([
                    'hora_actual' => $horaActual,
                    'horario_entrada' => $horarioEfectivo['horario']['hora_entrada'],
                    'horario_salida' => $horarioEfectivo['horario']['hora_salida']
                ], $horarioEfectivo['info_adicional'])
            ];

        } catch (\Exception $e) {
            Log::error("Error en validación de horario: " . $e->getMessage());
            return [
                'puede_acceder' => true, // Permitir acceso en caso de error
                'motivo' => 'ERROR_VALIDACION',
                'mensaje' => 'Error en validación de horario',
                'origen_horario' => 'ERROR'
            ];
        }
    }

    /**
     * ✅ OBTENER HORARIO EFECTIVO CON PRIORIDADES
     */
    private function obtenerHorarioEfectivo($usuario, $diaSemana, $now)
    {
        $horarioEfectivo = null;
        $origenHorario = 'NINGUNO';
        $infoAdicional = [];

        try {
            // 🥇 PRIORIDAD 1: Horario temporal
            $horarioTemporal = DB::table('gaf_jorusu_temp')
                ->where('temp_usu_id', $usuario->usu_id)
                ->where('temp_diasem_codigo', $diaSemana)
                ->where('temp_fecha_inicio', '<=', $now->format('Y-m-d'))
                ->where('temp_fecha_fin', '>=', $now->format('Y-m-d'))
                ->where('temp_activo', true)
                ->first();

            if ($horarioTemporal) {
                $horarioEfectivo = [
                    'hora_entrada' => $horarioTemporal->temp_horentrada,
                    'hora_salida' => $horarioTemporal->temp_horsalida
                ];
                $origenHorario = 'TEMPORAL';
                $infoAdicional = [
                    'tipo_temporal' => $horarioTemporal->temp_tipo,
                    'motivo_temporal' => $horarioTemporal->temp_motivo,
                    'fecha_fin_temporal' => $horarioTemporal->temp_fecha_fin,
                    'dias_restantes' => Carbon::parse($horarioTemporal->temp_fecha_fin)->diffInDays($now, false)
                ];
            }
            // 🥈 PRIORIDAD 2: Horario personalizado
            else {
                $horarioPersonalizado = DB::table('gaf_jorusu')
                    ->where('jorusu_usu_id', $usuario->usu_id)
                    ->where('jorusu_diasem_codigo', $diaSemana)
                    ->first();

                if ($horarioPersonalizado) {
                    $horarioEfectivo = [
                        'hora_entrada' => $horarioPersonalizado->jorusu_horentrada,
                        'hora_salida' => $horarioPersonalizado->jorusu_horsalida
                    ];
                    $origenHorario = 'PERSONALIZADO';
                }
                // 🥉 PRIORIDAD 3: Horario de oficina
                else {
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

        } catch (\Exception $e) {
            Log::error("Error obteniendo horario efectivo: " . $e->getMessage());
        }

        return [
            'horario' => $horarioEfectivo,
            'origen' => $origenHorario,
            'info_adicional' => $infoAdicional
        ];
    }

    /**
     * ✅ DETERMINAR MOTIVO DE FALLO
     */
    private function determinarMotivoFallo($origen)
    {
        switch ($origen) {
            case 'TEMPORAL':
                return 'FUERA_HORARIO_TEMPORAL';
            case 'PERSONALIZADO':
                return 'FUERA_HORARIO_PERSONAL';
            default:
                return 'FUERA_HORARIO';
        }
    }

    /**
     * ✅ MÉTODO AUXILIAR: Verificar si está dentro del horario
     */
    private function estaDentroDelHorario($horaConsulta, $horaEntrada, $horaSalida)
    {
        try {
            $consulta = Carbon::createFromFormat('H:i', $horaConsulta);
            $entrada = Carbon::createFromFormat('H:i', $horaEntrada);
            $salida = Carbon::createFromFormat('H:i', $horaSalida);

            if ($salida < $entrada) {
                // Horario nocturno que cruza medianoche
                return $consulta >= $entrada || $consulta <= $salida;
            } else {
                // Horario normal
                return $consulta >= $entrada && $consulta <= $salida;
            }
        } catch (\Exception $e) {
            Log::error("Error verificando horario en middleware: " . $e->getMessage());
            return true; // En caso de error, permitir acceso
        }
    }

    /**
     * ✅ DETERMINAR SI DEBE SALTAR VALIDACIÓN
     */
    private function shouldSkipValidation(Request $request, $user)
    {
        // ✅ RUTAS EXCLUIDAS DE VALIDACIÓN DE HORARIO
        $excludedRoutes = [
            'api/logout',
            'api/auth/verificar-horario',
            'api/user',
            'api/usuarios/me/horario-actual',
            'api/usuarios/me/horarios',
            'api/dashboard/stats'
        ];

        $currentRoute = $request->path();

        // Verificar rutas exactas
        if (in_array($currentRoute, $excludedRoutes)) {
            return true;
        }

        // Verificar patrones de rutas
        $excludedPatterns = [
            'api/usuarios/*/horarios*',
            'api/horarios/*',
            'api/logs/*',
            'api/admin/*'
        ];

        foreach ($excludedPatterns as $pattern) {
            if ($this->matchesPattern($currentRoute, $pattern)) {
                return true;
            }
        }

        // Super Admins pueden saltarse validación en rutas administrativas
        if ($user && $user->per_id == 3) {
            $adminPatterns = [
                'api/admin/*',
                'api/configs/*',
                'api/usuarios/*',
                'api/perfiles/*'
            ];

            foreach ($adminPatterns as $pattern) {
                if ($this->matchesPattern($currentRoute, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ✅ COINCIDENCIA DE PATRONES
     */
    private function matchesPattern($route, $pattern)
    {
        $pattern = str_replace('*', '.*', $pattern);
        return preg_match('#^' . $pattern . '$#', $route);
    }

    /**
     * ✅ REGISTRAR INTENTO FALLIDO
     */
    private function registrarIntentoFallido($usuario, $validacion, $request)
    {
        try {
            $now = Carbon::now('America/Guayaquil');

            $logData = [
                'logacc_usu_id' => $usuario->usu_id,
                'logacc_oficin_codigo' => $usuario->oficin_codigo ?: 0,
                'logacc_fecha_intento' => $now,
                'logacc_hora_intento' => $now->format('H:i:s'),
                'logacc_dia_semana' => $now->dayOfWeekIso,
                'logacc_tipo_intento' => $validacion['motivo'],
                'logacc_ip_address' => $request->ip(),
                'logacc_user_agent' => $request->userAgent(),
                'logacc_observaciones' => $validacion['mensaje']
            ];

            // Agregar información de horario si está disponible
            if (isset($validacion['detalles']['horario_entrada'])) {
                $logData['logacc_horario_esperado_inicio'] = $validacion['detalles']['horario_entrada'];
                $logData['logacc_horario_esperado_fin'] = $validacion['detalles']['horario_salida'];
                $logData['logacc_jornada'] = Carbon::createFromFormat('H:i', $validacion['detalles']['horario_entrada'])->hour < 12 ? 'MATUTINA' : 'NOCTURNA';
            }

            DB::table('gaf_logacc')->insert($logData);

        } catch (\Exception $e) {
            Log::error("❌ Error registrando intento fallido en middleware: " . $e->getMessage());
        }
    }

    /**
     * ✅ DENEGAR ACCESO
     */
    private function denyAccess($message, $errorCode, $details = [])
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error_code' => $errorCode,
            'debe_cerrar_sesion' => true,
            'details' => $details,
            'timestamp' => Carbon::now()->format('Y-m-d H:i:s')
        ], 403);
    }
}