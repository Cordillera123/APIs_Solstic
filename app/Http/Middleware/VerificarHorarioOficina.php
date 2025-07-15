<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VerificarHorarioOficina
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next, ...$exemptions)
    {
        // Solo verificar en rutas autenticadas
        if (!Auth::check()) {
            return $next($request);
        }

        try {
            $user = Auth::user();
            
            // Verificar que el usuario aún existe y está activo
            $usuario = DB::table('tbl_usu')
                ->where('usu_id', $user->usu_id)
                ->first();

            if (!$usuario) {
                Log::warning("🚫 Usuario no encontrado en middleware: {$user->usu_id}");
                return $this->responseDesautorizado('Usuario no encontrado', 'USER_NOT_FOUND');
            }

            if ($usuario->usu_deshabilitado === true) {
                Log::warning("🚫 Usuario deshabilitado en middleware: {$user->usu_id}");
                return $this->responseDesautorizado('Su cuenta ha sido desactivada', 'USER_DISABLED');
            }

            // EXCEPCIÓN 1: Super Admins (per_id = 3) sin restricciones
            if ($usuario->per_id == 3) {
                return $next($request);
            }

            // EXCEPCIÓN 2: Rutas específicas que no requieren validación de horario
            $rutasExentas = [
                'api/auth/logout',
                'api/auth/verificar-horario',
                'api/auth/user',
                'api/usuarios/me',
                'api/usuarios/me/basica',
                'api/logs/acceso-fallido'
            ];

            $rutaActual = $request->path();
            
            // Verificar si la ruta está exenta
            foreach ($rutasExentas as $rutaExenta) {
                if (str_starts_with($rutaActual, $rutaExenta)) {
                    return $next($request);
                }
            }

            // EXCEPCIÓN 3: Parámetros adicionales del middleware
            if (in_array('no-horario', $exemptions)) {
                return $next($request);
            }

            // Verificar horario de oficina
            $validacionHorario = $this->validarHorarioUsuario($usuario, $request);

            if (!$validacionHorario['puede_acceder']) {
                // Registrar intento automáticamente
                $this->registrarIntentoFallido($usuario, $validacionHorario, $request);
                
                Log::warning("🕐 Acceso denegado por horario en middleware", [
                    'usuario_id' => $usuario->usu_id,
                    'oficina' => $usuario->oficin_codigo,
                    'ruta' => $rutaActual,
                    'tipo' => $validacionHorario['tipo']
                ]);

                return $this->responseDesautorizado(
                    $validacionHorario['mensaje'], 
                    $validacionHorario['tipo'],
                    $validacionHorario['detalles']
                );
            }

            // ✅ HORARIO VÁLIDO - Continuar con la solicitud
            return $next($request);

        } catch (\Exception $e) {
            Log::error("❌ Error en middleware de horarios: " . $e->getMessage(), [
                'usuario_id' => $user->usu_id ?? 'N/A',
                'ruta' => $request->path(),
                'trace' => $e->getTraceAsString()
            ]);

            // En caso de error, permitir continuar para no bloquear el sistema
            return $next($request);
        }
    }

    /**
     * Validar horario del usuario
     */
    private function validarHorarioUsuario($usuario, $request)
    {
        // Usuario debe tener oficina asignada
        if (!$usuario->oficin_codigo) {
            return [
                'puede_acceder' => false,
                'tipo' => 'SIN_OFICINA',
                'mensaje' => 'No tiene oficina asignada. Su sesión será cerrada.',
                'detalles' => [
                    'usuario_id' => $usuario->usu_id,
                    'motivo' => 'Usuario sin oficina asignada'
                ]
            ];
        }

        // Verificar que la oficina esté activa
        $oficina = DB::table('gaf_oficin')
            ->where('oficin_codigo', $usuario->oficin_codigo)
            ->first();

        if (!$oficina || $oficina->oficin_ctractual != 1) {
            return [
                'puede_acceder' => false,
                'tipo' => 'OFICINA_INACTIVA',
                'mensaje' => 'Su oficina está inactiva. Su sesión será cerrada.',
                'detalles' => [
                    'oficina_codigo' => $usuario->oficin_codigo,
                    'oficina_activa' => $oficina ? $oficina->oficin_ctractual == 1 : false
                ]
            ];
        }

        $now = Carbon::now('America/Guayaquil'); // Usar la zona horaria configurada
        $diaSemana = $now->dayOfWeekIso; // 1=Lunes, 7=Domingo
        $horaActual = $now->format('H:i');

        // Obtener horario para el día actual
        $horario = DB::table('gaf_jorofi')
            ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
            ->where('gaf_jorofi.jorofi_oficin_codigo', $usuario->oficin_codigo)
            ->where('gaf_jorofi.jorofi_diasem_codigo', $diaSemana)
            ->where('gaf_jorofi.jorofi_ctrhabil', 1)
            ->select(
                'gaf_jorofi.*',
                'gaf_diasem.diasem_nombre'
            )
            ->first();

        if (!$horario) {
            return [
                'puede_acceder' => false,
                'tipo' => 'SIN_HORARIO',
                'mensaje' => 'No hay horario configurado para hoy. Su sesión será cerrada.',
                'detalles' => [
                    'oficina_codigo' => $usuario->oficin_codigo,
                    'dia_semana' => $diaSemana,
                    'fecha_actual' => $now->format('Y-m-d'),
                    'hora_actual' => $horaActual
                ]
            ];
        }

        // Validar si está dentro del horario
        $horaInicio = Carbon::createFromFormat('H:i', $horario->jorofi_horinicial);
        $horaFin = Carbon::createFromFormat('H:i', $horario->jorofi_horfinal);
        $horaConsulta = Carbon::createFromFormat('H:i', $horaActual);

        $dentroDelHorario = false;
        $cruzaMedianoche = $horaFin < $horaInicio;

        if ($cruzaMedianoche) {
            // Horario nocturno que cruza medianoche
            $dentroDelHorario = $horaConsulta >= $horaInicio || $horaConsulta <= $horaFin;
        } else {
            // Horario normal
            $dentroDelHorario = $horaConsulta >= $horaInicio && $horaConsulta <= $horaFin;
        }

        if (!$dentroDelHorario) {
            return [
                'puede_acceder' => false,
                'tipo' => 'FUERA_HORARIO',
                'mensaje' => 'Su horario de acceso ha finalizado (' . $horario->jorofi_horinicial . ' - ' . $horario->jorofi_horfinal . '). Su sesión será cerrada.',
                'detalles' => [
                    'oficina_codigo' => $usuario->oficin_codigo,
                    'dia_actual' => trim($horario->diasem_nombre),
                    'hora_actual' => $horaActual,
                    'horario_inicio' => $horario->jorofi_horinicial,
                    'horario_fin' => $horario->jorofi_horfinal,
                    'cruza_medianoche' => $cruzaMedianoche,
                    'jornada' => $horaInicio->hour < 12 ? 'MATUTINA' : 'NOCTURNA'
                ]
            ];
        }

        // ✅ ACCESO PERMITIDO
        return [
            'puede_acceder' => true,
            'tipo' => 'DENTRO_HORARIO',
            'mensaje' => 'Acceso permitido',
            'detalles' => [
                'oficina_codigo' => $usuario->oficin_codigo,
                'dia_actual' => trim($horario->diasem_nombre),
                'hora_actual' => $horaActual,
                'horario_inicio' => $horario->jorofi_horinicial,
                'horario_fin' => $horario->jorofi_horfinal,
                'cruza_medianoche' => $cruzaMedianoche,
                'jornada' => $horaInicio->hour < 12 ? 'MATUTINA' : 'NOCTURNA'
            ]
        ];
    }

    /**
     * Registrar intento fallido automáticamente
     */
    private function registrarIntentoFallido($usuario, $validacionHorario, $request)
    {
        try {
            $now = Carbon::now('America/Guayaquil'); // Usar la zona horaria configurada
            
            $logData = [
                'logacc_usu_id' => $usuario->usu_id,
                'logacc_oficin_codigo' => $usuario->oficin_codigo ?: 0,
                'logacc_fecha_intento' => $now,
                'logacc_hora_intento' => $now->format('H:i:s'),
                'logacc_dia_semana' => $now->dayOfWeekIso,
                'logacc_tipo_intento' => $validacionHorario['tipo'],
                'logacc_ip_address' => $request->ip(),
                'logacc_user_agent' => $request->userAgent(),
                'logacc_observaciones' => 'Middleware: ' . $validacionHorario['mensaje']
            ];

            // Agregar información específica según el tipo
            if (isset($validacionHorario['detalles']['horario_inicio'])) {
                $logData['logacc_horario_esperado_inicio'] = $validacionHorario['detalles']['horario_inicio'];
                $logData['logacc_horario_esperado_fin'] = $validacionHorario['detalles']['horario_fin'];
                $logData['logacc_jornada'] = $validacionHorario['detalles']['jornada'];
            }

            DB::table('gaf_logacc')->insert($logData);

            Log::info("📝 Intento fallido registrado desde middleware:", [
                'usuario_id' => $usuario->usu_id,
                'tipo' => $validacionHorario['tipo'],
                'oficina' => $usuario->oficin_codigo
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error registrando intento fallido desde middleware: " . $e->getMessage());
        }
    }

    /**
     * Respuesta estandarizada para acceso denegado
     */
    private function responseDesautorizado($mensaje, $tipo, $detalles = null)
    {
        $response = [
            'status' => 'error',
            'message' => $mensaje,
            'debe_cerrar_sesion' => true,
            'tipo_error' => $tipo,
            'timestamp' => Carbon::now()->toISOString()
        ];

        if ($detalles) {
            $response['detalles'] = $detalles;
        }

        return response()->json($response, 403);
    }
}