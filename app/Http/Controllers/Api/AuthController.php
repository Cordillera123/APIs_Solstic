<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Login de usuario con validación de horarios
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

        try {
            $usuario = Usuario::where('usu_cor', $request->email)->first();
            
            if (!$usuario) {
                Log::warning("🚫 Intento de login con email inexistente: {$request->email}", [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                return response()->json([
                    'message' => 'Credenciales inválidas'
                ], 401);
            }
            
            // Verificar que el usuario esté activo
            if ($usuario->est_id != 1) {
                Log::warning("🚫 Login de usuario inactivo: {$usuario->usu_id} - {$usuario->usu_cor}");
                return response()->json([
                    'message' => 'Usuario inactivo o suspendido'
                ], 403);
            }
            
            // Verificar que el usuario no esté deshabilitado
            if ($usuario->usu_deshabilitado === true) {
                Log::warning("🚫 Login de usuario deshabilitado: {$usuario->usu_id} - {$usuario->usu_cor}");
                return response()->json([
                    'message' => 'Su cuenta está desactivada. Contacte al administrador'
                ], 403);
            }

            // Verificar contraseña - Compatible con texto plano
            $passwordValid = false;
            
            if ($request->password === $usuario->usu_con) {
                $passwordValid = true;
            } else {
                try {
                    $passwordValid = Hash::check($request->password, $usuario->usu_con);
                } catch (\Exception $e) {
                    $passwordValid = false;
                }
            }
            
            if (!$passwordValid) {
                Log::warning("🚫 Contraseña incorrecta para usuario: {$usuario->usu_id} - {$usuario->usu_cor}");
                return response()->json([
                    'message' => 'Credenciales inválidas'
                ], 401);
            }

            // ✅ NUEVA VALIDACIÓN DE HORARIOS
            $validacionHorario = $this->validarHorarioAcceso($usuario, $request);
            
            if (!$validacionHorario['puede_acceder']) {
                // Registrar intento fallido automáticamente
                $this->registrarIntentoFallido($usuario, $validacionHorario, $request);
                
                return response()->json([
                    'message' => $validacionHorario['mensaje'],
                    'tipo_error' => $validacionHorario['tipo'],
                    'detalles' => $validacionHorario['detalles']
                ], 403);
            }

            // Login exitoso - actualizar último acceso
            $usuario->update([
                'usu_ultimo_acceso' => Carbon::now(),
                'usu_intentos_fallidos' => 0, // Resetear intentos fallidos
                'usu_bloqueado_hasta' => null
            ]);

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
                'oficina_codigo' => $usuario->oficin_codigo,
                'es_super_admin' => $usuario->per_id == 3
            ];
            
            // Obtener permisos del usuario
            $permisos = $this->getUserMenus($usuario->usu_id);

            // ✅ INCLUIR INFORMACIÓN DE HORARIO
            $infoHorario = $this->getInfoHorarioUsuario($usuario);
            
            Log::info("✅ Login exitoso: {$usuario->usu_id} - {$usuario->usu_cor}", [
                'oficina' => $usuario->oficin_codigo,
                'perfil' => $usuario->per_id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'message' => 'Login exitoso',
                'user' => $userInfo,
                'permisos' => $permisos,
                'horario_info' => $infoHorario,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error en login: " . $e->getMessage(), [
                'email' => $request->email,
                'ip' => $request->ip(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error interno del servidor durante el login'
            ], 500);
        }
    }

    /**
     * ✅ NUEVO: Validar horario de acceso
     */
    /**
 * ✅ MÉTODO ACTUALIZADO: Validar horario con nueva lógica de prioridades
 */
/**
 * ✅ MÉTODO CORREGIDO: Validar horario de acceso con manejo de errores
 */
public function validarHorarioAcceso($usuario, $request)
{
    try {
        // Excepciones: Super Admins (per_id = 3) no tienen restricciones de horario
        if ($usuario->per_id == 3) {
            Log::info("🔓 Acceso sin restricción de horario - Super Admin: {$usuario->usu_id}");
            return [
                'puede_acceder' => true,
                'tipo' => 'SUPER_ADMIN',
                'mensaje' => 'Acceso sin restricciones de horario',
                'detalles' => [
                    'es_super_admin' => true,
                    'oficina_codigo' => $usuario->oficin_codigo
                ]
            ];
        }

        // Usuario debe tener oficina asignada
        if (!$usuario->oficin_codigo) {
            Log::warning("🚫 Usuario sin oficina asignada: {$usuario->usu_id}");
            return [
                'puede_acceder' => false,
                'tipo' => 'SIN_OFICINA',
                'mensaje' => 'No tiene oficina asignada. Contacte al administrador.',
                'detalles' => [
                    'usuario_id' => $usuario->usu_id,
                    'motivo' => 'Usuario sin oficina asignada'
                ]
            ];
        }

        // Verificar que la oficina esté activa con manejo de errores
        $oficina = $this->getOficinaInfo($usuario->oficin_codigo);

        if (!$oficina || $oficina->oficin_ctractual != 1) {
            Log::warning("🚫 Oficina inactiva: {$usuario->oficin_codigo} para usuario {$usuario->usu_id}");
            return [
                'puede_acceder' => false,
                'tipo' => 'OFICINA_INACTIVA',
                'mensaje' => 'Su oficina está inactiva. Contacte al administrador.',
                'detalles' => [
                    'oficina_codigo' => $usuario->oficin_codigo,
                    'oficina_activa' => $oficina ? $oficina->oficin_ctractual == 1 : false
                ]
            ];
        }

        $now = Carbon::now('America/Guayaquil');
        $diaSemana = $now->dayOfWeekIso; // 1=Lunes, 7=Domingo
        $horaActual = $now->format('H:i:s');

        // ✅ NUEVA LÓGICA DE PRIORIDAD CON TEMPORALES
        $horarioEfectivo = $this->obtenerHorarioEfectivoParaLogin($usuario, $diaSemana, $now);

        if (!$horarioEfectivo['horario']) {
            Log::warning("🚫 Sin horario configurado: usuario {$usuario->usu_id}, día {$diaSemana}");
            return [
                'puede_acceder' => false,
                'tipo' => 'SIN_HORARIO',
                'mensaje' => 'No hay horario configurado para hoy. Contacte al administrador.',
                'detalles' => [
                    'oficina_codigo' => $usuario->oficin_codigo,
                    'dia_semana' => $diaSemana,
                    'fecha_actual' => $now->format('Y-m-d'),
                    'hora_actual' => $horaActual,
                    'origen_horario' => $horarioEfectivo['origen']
                ]
            ];
        }

        // Validar si está dentro del horario
        $dentroDelHorario = $this->estaDentroDelHorarioLogin(
            $horaActual,
            $horarioEfectivo['horario']['hora_entrada'],
            $horarioEfectivo['horario']['hora_salida']
        );

        if (!$dentroDelHorario) {
            $tipoFallo = $this->determinarTipoFalloLogin($horarioEfectivo['origen']);
            
            Log::warning("🚫 Fuera de horario: usuario {$usuario->usu_id}, tipo {$horarioEfectivo['origen']}");
            
            return [
                'puede_acceder' => false,
                'tipo' => $tipoFallo,
                'mensaje' => "Fuera del horario {$horarioEfectivo['origen']}. Horario: {$horarioEfectivo['horario']['hora_entrada']} - {$horarioEfectivo['horario']['hora_salida']}",
                'detalles' => array_merge([
                    'oficina_codigo' => $usuario->oficin_codigo,
                    'hora_actual' => $horaActual,
                    'horario_inicio' => $horarioEfectivo['horario']['hora_entrada'],
                    'horario_fin' => $horarioEfectivo['horario']['hora_salida'],
                    'origen_horario' => $horarioEfectivo['origen']
                ], $horarioEfectivo['info_adicional'])
            ];
        }

        // ✅ ACCESO PERMITIDO
        Log::info("✅ Acceso permitido con horario {$horarioEfectivo['origen']}: usuario {$usuario->usu_id}");
        return [
            'puede_acceder' => true,
            'tipo' => 'DENTRO_HORARIO',
            'mensaje' => "Acceso permitido con horario {$horarioEfectivo['origen']}",
            'detalles' => array_merge([
                'oficina_codigo' => $usuario->oficin_codigo,
                'hora_actual' => $horaActual,
                'horario_inicio' => $horarioEfectivo['horario']['hora_entrada'],
                'horario_fin' => $horarioEfectivo['horario']['hora_salida'],
                'origen_horario' => $horarioEfectivo['origen']
            ], $horarioEfectivo['info_adicional'])
        ];

    } catch (\Exception $e) {
        Log::error("❌ Error en validación de horario de acceso: " . $e->getMessage(), [
            'usuario_id' => $usuario->usu_id ?? 'unknown',
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        // En caso de error, permitir acceso pero registrar
        return [
            'puede_acceder' => true,
            'tipo' => 'ERROR_VALIDACION',
            'mensaje' => 'Error en validación de horario - acceso permitido temporalmente',
            'detalles' => [
                'error_mensaje' => $e->getMessage()
            ]
        ];
    }
}

/**
 * ✅ MÉTODO AUXILIAR: Obtener información de oficina con manejo de errores
 */
private function getOficinaInfo($oficinCodigo)
{
    try {
        return DB::table('gaf_oficin')
            ->where('oficin_codigo', $oficinCodigo)
            ->first();
    } catch (\Exception $e) {
        Log::error("Error obteniendo información de oficina: " . $e->getMessage());
        return null;
    }
}

/**
 * ✅ MÉTODO AUXILIAR: Obtener horario efectivo para login
 */
private function obtenerHorarioEfectivoParaLogin($usuario, $diaSemana, $now)
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
                'fecha_fin_temporal' => $horarioTemporal->temp_fecha_fin
            ];
        }
        // 🥈 PRIORIDAD 2: Horario personalizado permanente
        elseif (!$horarioTemporal) {
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
            // 🥉 PRIORIDAD 3: Horario heredado de oficina
            else {
                $horarioOficina = DB::table('gaf_jorofi')
                    ->leftJoin('gaf_diasem', 'gaf_jorofi.jorofi_diasem_codigo', '=', 'gaf_diasem.diasem_codigo')
                    ->where('gaf_jorofi.jorofi_oficin_codigo', $usuario->oficin_codigo)
                    ->where('gaf_jorofi.jorofi_diasem_codigo', $diaSemana)
                    ->where('gaf_jorofi.jorofi_ctrhabil', 1)
                    ->select('gaf_jorofi.*', 'gaf_diasem.diasem_nombre')
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
 * ✅ MÉTODO AUXILIAR: Verificar si está dentro del horario para login
 */
private function estaDentroDelHorarioLogin($horaConsulta, $horaEntrada, $horaSalida)
{
    try {
        $consulta = Carbon::createFromFormat('H:i:s', $horaConsulta);
        $entrada = Carbon::createFromFormat('H:i', $horaEntrada);
        $salida = Carbon::createFromFormat('H:i', $horaSalida);

        // Verificar si el horario cruza medianoche
        if ($salida < $entrada) {
            // Horario nocturno que cruza medianoche
            return $consulta >= $entrada || $consulta <= $salida;
        } else {
            // Horario normal
            return $consulta >= $entrada && $consulta <= $salida;
        }
    } catch (\Exception $e) {
        Log::error("Error verificando horario en login: " . $e->getMessage());
        return true; // En caso de error, permitir acceso
    }
}

/**
 * ✅ MÉTODO AUXILIAR: Determinar tipo de fallo
 */
private function determinarTipoFalloLogin($origen)
{
    switch ($origen) {
        case 'TEMPORAL':
            return 'FUERA_HORARIO_TEMPORAL';
        case 'PERSONALIZADO':
            return 'FUERA_HORARIO_PERSONAL';
        case 'HEREDADO_OFICINA':
            return 'FUERA_HORARIO_OFICINA';
        default:
            return 'FUERA_HORARIO';
    }
}
/**
 * ✅ MÉTODO AUXILIAR: Verificar si una hora está dentro del rango
 */
private function estaDentroDelHorario($horaConsulta, $horaEntrada, $horaSalida)
{
    try {
        $consulta = Carbon::createFromFormat('H:i', $horaConsulta);
        $entrada = Carbon::createFromFormat('H:i', $horaEntrada);
        $salida = Carbon::createFromFormat('H:i', $horaSalida);

        // Verificar si el horario cruza medianoche (ej: 22:00 - 06:00)
        if ($salida < $entrada) {
            // Horario nocturno que cruza medianoche
            return $consulta >= $entrada || $consulta <= $salida;
        } else {
            // Horario normal (ej: 08:00 - 17:00)
            return $consulta >= $entrada && $consulta <= $salida;
        }
    } catch (\Exception $e) {
        Log::error("Error verificando horario: " . $e->getMessage());
        return false;
    }
}
    /**
     * ✅ NUEVO: Registrar intento fallido automáticamente
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
                'logacc_observaciones' => $validacionHorario['mensaje']
            ];

            // Agregar información específica según el tipo
            if (isset($validacionHorario['detalles']['horario_inicio'])) {
                $logData['logacc_horario_esperado_inicio'] = $validacionHorario['detalles']['horario_inicio'];
                $logData['logacc_horario_esperado_fin'] = $validacionHorario['detalles']['horario_fin'];
                $logData['logacc_jornada'] = $validacionHorario['detalles']['jornada'];
            }

            DB::table('gaf_logacc')->insert($logData);

            Log::info("📝 Intento fallido registrado:", [
                'usuario_id' => $usuario->usu_id,
                'tipo' => $validacionHorario['tipo'],
                'oficina' => $usuario->oficin_codigo
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error registrando intento fallido: " . $e->getMessage());
        }
    }

    /**
     * ✅ NUEVO: Obtener información de horario del usuario
     */
    private function getInfoHorarioUsuario($usuario)
    {
        // Super Admins no tienen restricciones
        if ($usuario->per_id == 3) {
            return [
                'es_super_admin' => true,
                'tiene_restricciones' => false,
                'mensaje' => 'Sin restricciones de horario'
            ];
        }

        if (!$usuario->oficin_codigo) {
            return [
                'es_super_admin' => false,
                'tiene_restricciones' => true,
                'tiene_oficina' => false,
                'mensaje' => 'Sin oficina asignada'
            ];
        }

        $now = Carbon::now('America/Guayaquil'); // Usar la zona horaria configurada
        $diaSemana = $now->dayOfWeekIso;

        // Obtener horario actual
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
                'es_super_admin' => false,
                'tiene_restricciones' => true,
                'tiene_oficina' => true,
                'tiene_horario_hoy' => false,
                'oficina_codigo' => $usuario->oficin_codigo,
                'mensaje' => 'Sin horario para hoy'
            ];
        }

        // Calcular tiempo restante hasta el cierre
        $horaActual = Carbon::createFromFormat('H:i', $now->format('H:i'));
        $horaFin = Carbon::createFromFormat('H:i:s', $horario->jorofi_horfinal);
        $horaInicio = Carbon::createFromFormat('H:i:s', $horario->jorofi_horinicial);
        
        $tiempoRestante = null;
        $alertaCierre = false;
        
        if ($horaFin < $horaInicio) {
            // Horario nocturno
            if ($horaActual >= $horaInicio) {
                $tiempoRestante = $horaActual->diffInMinutes($horaFin->addDay());
            } else {
                $tiempoRestante = $horaActual->diffInMinutes($horaFin);
            }
        } else {
            // Horario normal
            if ($horaActual <= $horaFin) {
                $tiempoRestante = $horaActual->diffInMinutes($horaFin);
            }
        }

        // Alerta si queda 1 minuto o menos
        if ($tiempoRestante !== null && $tiempoRestante <= 1) {
            $alertaCierre = true;
        }

        return [
            'es_super_admin' => false,
            'tiene_restricciones' => true,
            'tiene_oficina' => true,
            'tiene_horario_hoy' => true,
            'oficina_codigo' => $usuario->oficin_codigo,
            'dia_actual' => trim($horario->diasem_nombre),
            'horario' => [
                'inicio' => $horario->jorofi_horinicial,
                'fin' => $horario->jorofi_horfinal,
                'formato_visual' => $horario->jorofi_horinicial . ' - ' . $horario->jorofi_horfinal
            ],
            'tiempo_restante_minutos' => $tiempoRestante,
            'alerta_cierre_proximo' => $alertaCierre,
            'mensaje' => $alertaCierre ? 
                'Su sesión se cerrará en ' . $tiempoRestante . ' minuto(s)' : 
                'Dentro del horario permitido'
        ];
    }

    /**
     * ✅ NUEVO: Verificar horario de usuario activo (para middleware)
     */
    public function verificarHorarioActivo(Request $request)
    {
        try {
            // Si no hay header de Authorization, retornar que no está autenticado
            if (!$request->hasHeader('Authorization')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se encontró token de autenticación',
                    'authenticated' => false
                ], 200); // 200 porque es una consulta válida
            }

            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Token de autenticación inválido',
                    'debe_cerrar_sesion' => true,
                    'authenticated' => false
                ], 401);
            }

            // Obtener usuario completo
            $usuario = Usuario::find($user->usu_id);
            
            if (!$usuario || $usuario->usu_deshabilitado === true) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Su sesión ha sido revocada por el administrador',
                    'debe_cerrar_sesion' => true,
                    'error' => 'USER_DISABLED',
                    'authenticated' => false
                ], 403);
            }

            // Validar horario actual
            $validacionHorario = $this->validarHorarioAcceso($usuario, $request);
            
            if (!$validacionHorario['puede_acceder']) {
                // Registrar que se cerró por horario
                Log::info("🕐 Cerrando sesión por horario: {$usuario->usu_id} - {$validacionHorario['tipo']}");
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Su horario de acceso ha finalizado',
                    'debe_cerrar_sesion' => true,
                    'tipo_error' => $validacionHorario['tipo'],
                    'detalles' => $validacionHorario['detalles'],
                    'authenticated' => true
                ], 403);
            }

            // Obtener información actualizada de horario
            $infoHorario = $this->getInfoHorarioUsuario($usuario);

            return response()->json([
                'status' => 'success',
                'message' => 'Horario válido',
                'horario_info' => $infoHorario,
                'debe_cerrar_sesion' => false,
                'authenticated' => true
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error verificando horario activo: " . $e->getMessage());
            Log::error("❌ Stack trace: " . $e->getTraceAsString());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor: ' . $e->getMessage(),
                'debe_cerrar_sesion' => false,
                'authenticated' => false
            ], 500);
        }
    }

    /**
     * Logout de usuario (Revocar token)
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            
            if ($user) {
                Log::info("👋 Logout usuario: {$user->usu_id}");
                $request->user()->currentAccessToken()->delete();
            }
            
            return response()->json([
                'message' => 'Sesión cerrada exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Error en logout: " . $e->getMessage());
            return response()->json([
                'message' => 'Sesión cerrada'
            ]);
        }
    }
    
    /**
     * Obtener información del usuario autenticado
     */
    public function user(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $usuarioActual = Usuario::find($user->usu_id);
            
            if (!$usuarioActual || $usuarioActual->usu_deshabilitado === true) {
                return response()->json([
                    'message' => 'Su sesión ha sido revocada por el administrador',
                    'error' => 'USER_DISABLED'
                ], 403);
            }

            $userInfo = DB::table('tbl_usu')
                ->join('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
                ->select(
                    'tbl_usu.usu_id as id',
                    'tbl_usu.usu_cor as email',
                    DB::raw("CONCAT(tbl_usu.usu_nom, ' ', tbl_usu.usu_ape) as nombre"),
                    'tbl_per.per_nom as perfil',
                    'tbl_usu.oficin_codigo',
                    'tbl_usu.per_id'
                )
                ->where('tbl_usu.usu_id', $user->usu_id)
                ->first();
            
            // Obtener permisos del usuario
            $permisos = $this->getUserMenus($user->usu_id);
            
            // Obtener información de horario actualizada
            $infoHorario = $this->getInfoHorarioUsuario($usuarioActual);
            
            return response()->json([
                'status' => 'success',
                'user' => $userInfo,
                'permisos' => $permisos,
                'horario_info' => $infoHorario
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo usuario: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    // ... [Resto de métodos getUserMenus, etc. - mantener los existentes]
    
    /**
     * Obtener menús y permisos del usuario incluyendo iconos
     */
    public function getUserMenus($userId)
    {
        error_log('DEBUG 1 - Iniciando getUserMenus para usuario: ' . $userId);
        
        // Obtener el perfil del usuario
        $usuario = DB::table('tbl_usu')
            ->join('tbl_per', 'tbl_usu.per_id', '=', 'tbl_per.per_id')
            ->select('tbl_per.per_id', 'tbl_usu.usu_id', 'tbl_per.per_nom', 'tbl_usu.usu_nom', 'tbl_usu.usu_ape', 'tbl_usu.usu_deshabilitado')
            ->where('tbl_usu.usu_id', $userId)
            ->where('tbl_usu.usu_deshabilitado', '!=', true)
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
        // Verificar que el usuario exista y obtener su perfil
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
 * ✅ MÉTODO DE DEBUG: Para desarrollo y testing
 */
public function debugUserPermissions($userId)
{
    try {
        $debug = [
            'usuario_id' => $userId,
            'timestamp' => Carbon::now(),
            'validacion_horario' => $this->validarHorarioAccesoIndividual($userId),
            'horarios_configurados' => []
        ];

        // Obtener todos los horarios del usuario
        $debug['horarios_configurados']['temporales'] = DB::table('gaf_jorusu_temp')
            ->where('temp_usu_id', $userId)
            ->where('temp_activo', true)
            ->get();

        $debug['horarios_configurados']['personalizados'] = DB::table('gaf_jorusu')
            ->where('jorusu_usu_id', $userId)
            ->get();

        $usuario = DB::table('tbl_usu')->where('usu_id', $userId)->first();
        if ($usuario && $usuario->oficin_codigo) {
            $debug['horarios_configurados']['oficina'] = DB::table('gaf_jorofi')
                ->where('jorofi_oficin_codigo', $usuario->oficin_codigo)
                ->where('jorofi_ctrhabil', 1)
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'debug_info' => $debug
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error en debug: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * ✅ MÉTODO DE TESTING: Para probar horario de usuario
 */
public function testHorarioUsuario($usuarioId)
{
    try {
        $usuario = DB::table('tbl_usu')->where('usu_id', $usuarioId)->first();
        
        if (!$usuario) {
            return response()->json([
                'error' => 'Usuario no encontrado'
            ], 404);
        }
        
        $validacion = $this->validarHorarioAcceso($usuario, request());
        
        return response()->json([
            'usuario_id' => $usuarioId,
            'validacion' => $validacion,
            'timestamp' => now()
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Error en test: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * ✅ MÉTODO AUXILIAR: Validación individual para debugging
 */
private function validarHorarioAccesoIndividual($userId)
{
    $usuario = DB::table('tbl_usu')->where('usu_id', $userId)->first();
    
    if (!$usuario) {
        return [
            'puede_acceder' => false,
            'motivo' => 'USUARIO_NO_ENCONTRADO'
        ];
    }
    
    return $this->validarHorarioAcceso($usuario, request());
}
}