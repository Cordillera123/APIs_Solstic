<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DirectModulesController extends Controller
{
    /**
     * Obtener todos los perfiles con el estado de sus módulos directos
     */
    public function getPerfilesWithDirectModules()
    {
        try {
            $perfiles = DB::table('tbl_per')
                ->where('per_activo', true)
                ->select('per_id', 'per_nom', 'per_descripcion', 'per_nivel')
                ->orderBy('per_nom')
                ->get();

            foreach ($perfiles as $perfil) {
                // ✅ USAR EL NUEVO MÉTODO FILTRADO
                $modulosDirectos = $this->getModulosDirectosConPermisos($perfil->per_id);
                $perfil->modulos_directos = $modulosDirectos;

                // Estadísticas actualizadas
                $perfil->estadisticas = [
                    'total_modulos_directos' => count($modulosDirectos),
                    'modulos_con_acceso' => count($modulosDirectos), // ✅ Todos tienen acceso porque están filtrados
                    'modulos_con_botones' => count(array_filter($modulosDirectos, fn($m) => $m['tiene_botones_configurados']))
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Perfiles con módulos directos obtenidos correctamente',
                'perfiles' => $perfiles
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo perfiles con módulos directos: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener estado de módulos directos para un perfil específico
     */
    public function getModulosDirectosForPerfil($perfilId)
    {
        try {
            $perfil = DB::table('tbl_per')->where('per_id', $perfilId)->first();

            if (!$perfil) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Perfil no encontrado'
                ], 404);
            }

            // ✅ USAR EL NUEVO MÉTODO FILTRADO
            $modulosDirectos = $this->getModulosDirectosConPermisos($perfilId);

            return response()->json([
                'status' => 'success',
                'perfil' => $perfil,
                'modulos_directos' => $modulosDirectos
            ]);
        } catch (\Exception $e) {
            Log::error("Error obteniendo módulos directos para perfil {$perfilId}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Asignar/Revocar acceso completo a un módulo directo
     */
    public function toggleModuloDirectoAccess(Request $request, $perfilId)
    {
        $validator = Validator::make($request->all(), [
            'men_id' => 'nullable|integer|exists:tbl_men,men_id',
            'sub_id' => 'nullable|integer|exists:tbl_sub,sub_id',
            'opc_id' => 'nullable|integer|exists:tbl_opc,opc_id',
            'grant_access' => 'required|boolean',
            'tipo' => 'required|in:menu,submenu,opcion'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $menId = $request->men_id;
            $subId = $request->sub_id;
            $opcId = $request->opc_id;
            $grantAccess = $request->grant_access;
            $tipo = $request->tipo;

            if ($grantAccess) {
                // OTORGAR ACCESO
                $result = $this->otorgarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo);
            } else {
                // REVOCAR ACCESO
                $result = $this->revocarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $result['message'],
                'data' => $result['data']
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en toggle módulo directo: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al modificar acceso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignación masiva de módulos directos a un perfil
     */
    public function asignacionMasiva(Request $request, $perfilId)
    {
        $validator = Validator::make($request->all(), [
            'accion' => 'required|in:otorgar_todos,revocar_todos,solo_lectura',
            'modulos_especificos' => 'array',
            'modulos_especificos.*.men_id' => 'nullable|integer',
            'modulos_especificos.*.sub_id' => 'nullable|integer',
            'modulos_especificos.*.opc_id' => 'nullable|integer',
            'modulos_especificos.*.tipo' => 'required_with:modulos_especificos|in:menu,submenu,opcion'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $accion = $request->accion;
            $modulosEspecificos = $request->modulos_especificos ?? [];

            $estadisticas = [
                'modulos_procesados' => 0,
                'permisos_creados' => 0,
                'permisos_revocados' => 0,
                'errores' => []
            ];

            if ($accion === 'otorgar_todos') {
                // Otorgar acceso a todos los módulos directos
                $modulosDirectos = $this->getTodosLosModulosDirectos();

                foreach ($modulosDirectos as $modulo) {
                    try {
                        $result = $this->otorgarAccesoCompleto(
                            $perfilId,
                            $modulo['men_id'],
                            $modulo['sub_id'],
                            $modulo['opc_id'],
                            $modulo['tipo']
                        );
                        $estadisticas['modulos_procesados']++;
                        $estadisticas['permisos_creados'] += $result['data']['permisos_creados'];
                    } catch (\Exception $e) {
                        $estadisticas['errores'][] = "Error en {$modulo['nombre']}: " . $e->getMessage();
                    }
                }
            } elseif ($accion === 'revocar_todos') {
                // Revocar todos los accesos
                $permisosRevocados = DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $perfilId)
                    ->whereIn('men_id', function ($query) {
                        $query->select('men_id')
                            ->from('tbl_men')
                            ->where('men_ventana_directa', true);
                    })
                    ->delete();

                $permisosPerfilRevocados = DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfilId)
                    ->whereIn('men_id', function ($query) {
                        $query->select('men_id')
                            ->from('tbl_men')
                            ->where('men_ventana_directa', true);
                    })
                    ->delete();

                $estadisticas['permisos_revocados'] = $permisosRevocados + $permisosPerfilRevocados;
            } elseif ($accion === 'solo_lectura') {
                // Dar solo permisos de lectura
                foreach ($modulosEspecificos as $modulo) {
                    try {
                        $result = $this->otorgarAccesoSoloLectura(
                            $perfilId,
                            $modulo['men_id'],
                            $modulo['sub_id'],
                            $modulo['opc_id'],
                            $modulo['tipo']
                        );
                        $estadisticas['modulos_procesados']++;
                        $estadisticas['permisos_creados'] += $result['data']['permisos_creados'];
                    } catch (\Exception $e) {
                        $estadisticas['errores'][] = "Error en módulo: " . $e->getMessage();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Asignación masiva completada: {$accion}",
                'estadisticas' => $estadisticas
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error en asignación masiva: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error en asignación masiva: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copiar configuración de módulos directos de un perfil a otro
     */
    public function copiarConfiguracion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'perfil_origen_id' => 'required|integer|exists:tbl_per,per_id',
            'perfil_destino_id' => 'required|integer|exists:tbl_per,per_id',
            'sobrescribir' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $perfilOrigenId = $request->perfil_origen_id;
            $perfilDestinoId = $request->perfil_destino_id;
            $sobrescribir = $request->sobrescribir ?? false;

            if ($perfilOrigenId === $perfilDestinoId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El perfil origen y destino no pueden ser el mismo'
                ], 422);
            }

            // Si se especifica sobrescribir, limpiar configuración existente
            if ($sobrescribir) {
                DB::table('tbl_perm_bot_perfil')
                    ->where('per_id', $perfilDestinoId)
                    ->whereIn('men_id', function ($query) {
                        $query->select('men_id')->from('tbl_men')->where('men_ventana_directa', true);
                    })
                    ->delete();

                DB::table('tbl_perm_perfil')
                    ->where('per_id', $perfilDestinoId)
                    ->whereIn('men_id', function ($query) {
                        $query->select('men_id')->from('tbl_men')->where('men_ventana_directa', true);
                    })
                    ->delete();
            }

            // Copiar permisos de perfil
            $permisosPerfilCopiados = DB::statement("
                INSERT INTO tbl_perm_perfil (per_id, men_id, sub_id, opc_id, perm_per_activo, perm_per_cre, perm_per_edi)
                SELECT ?, pp.men_id, pp.sub_id, pp.opc_id, pp.perm_per_activo, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM tbl_perm_perfil pp
                JOIN tbl_men m ON pp.men_id = m.men_id
                WHERE pp.per_id = ? AND m.men_ventana_directa = true
                ON CONFLICT (per_id, men_id, sub_id, opc_id) DO NOTHING
            ", [$perfilDestinoId, $perfilOrigenId]);

            // Copiar permisos de botones
            $permisosBotonesCopiados = DB::statement("
                INSERT INTO tbl_perm_bot_perfil (per_id, men_id, sub_id, opc_id, bot_id, perm_bot_per_activo, perm_bot_per_cre, perm_bot_per_edi)
                SELECT ?, pbp.men_id, pbp.sub_id, pbp.opc_id, pbp.bot_id, pbp.perm_bot_per_activo, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM tbl_perm_bot_perfil pbp
                JOIN tbl_men m ON pbp.men_id = m.men_id
                WHERE pbp.per_id = ? AND m.men_ventana_directa = true
                ON CONFLICT (per_id, men_id, sub_id, opc_id, bot_id) DO NOTHING
            ", [$perfilDestinoId, $perfilOrigenId]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Configuración copiada correctamente',
                'data' => [
                    'perfil_origen' => $perfilOrigenId,
                    'perfil_destino' => $perfilDestinoId,
                    'sobrescribio' => $sobrescribir
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error copiando configuración: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al copiar configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============== MÉTODOS AUXILIARES ==============

    private function getModulosDirectosStatus($perfilId)
    {
        $modulos = [];

        // ===== 1. MENÚS DIRECTOS CON PERMISOS =====
        $menusDirectos = DB::table('tbl_men')
            ->join('tbl_perm_perfil', function ($join) use ($perfilId) {
                $join->on('tbl_men.men_id', '=', 'tbl_perm_perfil.men_id')
                    ->where('tbl_perm_perfil.per_id', $perfilId)
                    ->where('tbl_perm_perfil.perm_per_activo', true)
                    ->whereNull('tbl_perm_perfil.sub_id')
                    ->whereNull('tbl_perm_perfil.opc_id');
            })
            ->where('tbl_men.men_ventana_directa', true)
            ->where('tbl_men.men_activo', true)
            ->select('tbl_men.*')
            ->get();

        foreach ($menusDirectos as $menu) {
            $tieneAcceso = $this->verificarAccesoCompleto($perfilId, $menu->men_id, null, null, 'menu');

            $modulos[] = [
                'men_id' => $menu->men_id,
                'sub_id' => null,
                'opc_id' => null,
                'tipo' => 'menu',
                'nombre' => $menu->men_nom,
                'componente' => $menu->men_componente,
                'tiene_acceso' => true, // ✅ Siempre true porque ya filtramos por permisos
                'tiene_botones_configurados' => $tieneAcceso['botones_configurados'] > 0,
                'botones_con_permiso' => $tieneAcceso['botones_con_permiso'],
                'botones_configurados' => $tieneAcceso['botones_configurados']
            ];
        }

        // ===== 2. SUBMENÚS DIRECTOS CON PERMISOS =====
        $submenusDirectos = DB::table('tbl_sub')
            ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->join('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
            ->join('tbl_perm_perfil', function ($join) use ($perfilId) {
                $join->where('tbl_perm_perfil.per_id', $perfilId)
                    ->where('tbl_perm_perfil.perm_per_activo', true)
                    ->where(function ($query) {
                        // Permiso específico al submenú O permiso heredado del menú
                        $query->where(function ($q) {
                            $q->whereColumn('tbl_perm_perfil.men_id', 'tbl_men.men_id')
                                ->whereColumn('tbl_perm_perfil.sub_id', 'tbl_sub.sub_id')
                                ->whereNull('tbl_perm_perfil.opc_id');
                        })->orWhere(function ($q) {
                            $q->whereColumn('tbl_perm_perfil.men_id', 'tbl_men.men_id')
                                ->whereNull('tbl_perm_perfil.sub_id')
                                ->whereNull('tbl_perm_perfil.opc_id');
                        });
                    });
            })
            ->where('tbl_sub.sub_ventana_directa', true)
            ->where('tbl_sub.sub_activo', true)
            ->where('tbl_men.men_activo', true)
            ->select('tbl_sub.*', 'tbl_men.men_id', 'tbl_men.men_nom')
            ->distinct()
            ->get();

        foreach ($submenusDirectos as $submenu) {
            $tieneAcceso = $this->verificarAccesoCompleto($perfilId, $submenu->men_id, $submenu->sub_id, null, 'submenu');

            $modulos[] = [
                'men_id' => $submenu->men_id,
                'sub_id' => $submenu->sub_id,
                'opc_id' => null,
                'tipo' => 'submenu',
                'nombre' => $submenu->men_nom . ' > ' . $submenu->sub_nom,
                'componente' => $submenu->sub_componente,
                'tiene_acceso' => true, // ✅ Siempre true porque ya filtramos por permisos
                'tiene_botones_configurados' => $tieneAcceso['botones_configurados'] > 0,
                'botones_con_permiso' => $tieneAcceso['botones_con_permiso'],
                'botones_configurados' => $tieneAcceso['botones_configurados']
            ];
        }

        // ===== 3. OPCIONES DIRECTAS CON PERMISOS =====
        $opcionesDirectas = DB::table('tbl_opc')
            ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
            ->join('tbl_sub', 'tbl_sub_opc.sub_id', '=', 'tbl_sub.sub_id')
            ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->join('tbl_men', 'tbl_men_sub.men_id', '=', 'tbl_men.men_id')
            ->join('tbl_perm_perfil', function ($join) use ($perfilId) {
                $join->where('tbl_perm_perfil.per_id', $perfilId)
                    ->where('tbl_perm_perfil.perm_per_activo', true)
                    ->where(function ($query) {
                        // Permiso específico a la opción O permiso heredado del submenú O del menú
                        $query->where(function ($q) {
                            // Permiso específico a la opción
                            $q->whereColumn('tbl_perm_perfil.men_id', 'tbl_men.men_id')
                                ->whereColumn('tbl_perm_perfil.sub_id', 'tbl_sub.sub_id')
                                ->whereColumn('tbl_perm_perfil.opc_id', 'tbl_opc.opc_id');
                        })->orWhere(function ($q) {
                            // Permiso heredado del submenú
                            $q->whereColumn('tbl_perm_perfil.men_id', 'tbl_men.men_id')
                                ->whereColumn('tbl_perm_perfil.sub_id', 'tbl_sub.sub_id')
                                ->whereNull('tbl_perm_perfil.opc_id');
                        })->orWhere(function ($q) {
                            // Permiso heredado del menú
                            $q->whereColumn('tbl_perm_perfil.men_id', 'tbl_men.men_id')
                                ->whereNull('tbl_perm_perfil.sub_id')
                                ->whereNull('tbl_perm_perfil.opc_id');
                        });
                    });
            })
            ->where('tbl_opc.opc_ventana_directa', true)
            ->where('tbl_opc.opc_activo', true)
            ->where('tbl_sub.sub_activo', true)
            ->where('tbl_men.men_activo', true)
            ->select(
                'tbl_opc.*',
                'tbl_sub.sub_id',
                'tbl_sub.sub_nom',
                'tbl_men.men_id',
                'tbl_men.men_nom'
            )
            ->distinct()
            ->get();

        foreach ($opcionesDirectas as $opcion) {
            $tieneAcceso = $this->verificarAccesoCompleto(
                $perfilId,
                $opcion->men_id,
                $opcion->sub_id,
                $opcion->opc_id,
                'opcion'
            );

            $modulos[] = [
                'men_id' => $opcion->men_id,
                'sub_id' => $opcion->sub_id,
                'opc_id' => $opcion->opc_id,
                'tipo' => 'opcion',
                'nombre' => $opcion->men_nom . ' > ' . $opcion->sub_nom . ' > ' . $opcion->opc_nom,
                'componente' => $opcion->opc_componente,
                'tiene_acceso' => true, // ✅ Siempre true porque ya filtramos por permisos
                'tiene_botones_configurados' => $tieneAcceso['botones_configurados'] > 0,
                'botones_con_permiso' => $tieneAcceso['botones_con_permiso'],
                'botones_configurados' => $tieneAcceso['botones_configurados']
            ];
        }

        return $modulos;
    }
    private function getModulosDirectosConPermisos($perfilId)
    {
        Log::info("🔍 Obteniendo módulos directos con permisos para perfil: {$perfilId}");

        $modulos = [];

        // Obtener todos los permisos activos del perfil para módulos directos
        $permisosDirectos = DB::select("
        SELECT DISTINCT
            pp.men_id,
            pp.sub_id,
            pp.opc_id,
            m.men_nom,
            m.men_componente,
            m.men_ventana_directa,
            s.sub_nom,
            s.sub_componente,
            s.sub_ventana_directa,
            o.opc_nom,
            o.opc_componente,
            o.opc_ventana_directa,
            CASE 
                WHEN pp.opc_id IS NOT NULL AND o.opc_ventana_directa = true THEN 'opcion'
                WHEN pp.sub_id IS NOT NULL AND pp.opc_id IS NULL AND s.sub_ventana_directa = true THEN 'submenu'
                WHEN pp.sub_id IS NULL AND pp.opc_id IS NULL AND m.men_ventana_directa = true THEN 'menu'
                ELSE NULL
            END as tipo
        FROM tbl_perm_perfil pp
        JOIN tbl_men m ON pp.men_id = m.men_id
        LEFT JOIN tbl_sub s ON pp.sub_id = s.sub_id
        LEFT JOIN tbl_opc o ON pp.opc_id = o.opc_id
        WHERE pp.per_id = ?
          AND pp.perm_per_activo = true
          AND m.men_activo = true
          AND (s.sub_id IS NULL OR s.sub_activo = true)
          AND (o.opc_id IS NULL OR o.opc_activo = true)
          AND (
              (pp.opc_id IS NOT NULL AND o.opc_ventana_directa = true) OR
              (pp.sub_id IS NOT NULL AND pp.opc_id IS NULL AND s.sub_ventana_directa = true) OR
              (pp.sub_id IS NULL AND pp.opc_id IS NULL AND m.men_ventana_directa = true)
          )
        ORDER BY m.men_nom, s.sub_nom, o.opc_nom
    ", [$perfilId]);

        foreach ($permisosDirectos as $permiso) {
            if (!$permiso->tipo) continue; // Saltar si no es módulo directo

            // Verificar estado de botones
            $tieneAcceso = $this->verificarAccesoCompleto(
                $perfilId,
                $permiso->men_id,
                $permiso->sub_id,
                $permiso->opc_id,
                $permiso->tipo
            );

            // Construir nombre completo del módulo
            $nombre = $permiso->men_nom;
            if ($permiso->sub_nom) {
                $nombre .= ' > ' . $permiso->sub_nom;
            }
            if ($permiso->opc_nom) {
                $nombre .= ' > ' . $permiso->opc_nom;
            }

            // Determinar componente
            $componente = $permiso->opc_componente ?? $permiso->sub_componente ?? $permiso->men_componente;

            $modulos[] = [
                'men_id' => $permiso->men_id,
                'sub_id' => $permiso->sub_id,
                'opc_id' => $permiso->opc_id,
                'tipo' => $permiso->tipo,
                'nombre' => $nombre,
                'componente' => $componente,
                'tiene_acceso' => true, // ✅ Siempre true porque ya está filtrado
                'tiene_botones_configurados' => $tieneAcceso['botones_configurados'] > 0,
                'botones_con_permiso' => $tieneAcceso['botones_con_permiso'],
                'botones_configurados' => $tieneAcceso['botones_configurados']
            ];
        }

        Log::info("✅ Módulos directos encontrados para perfil {$perfilId}: " . count($modulos));

        return $modulos;
    }

    private function verificarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo)
    {
        // Verificar permiso de perfil
        $tienePermisoProfile = DB::table('tbl_perm_perfil') // ✅ CORREGIDO: usar tbl_perm_perfil
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->where('perm_per_activo', true) // ✅ AGREGADO
            ->exists();

        // Contar botones configurados y con permiso
        $tabla = $tipo === 'menu' ? 'tbl_men_bot' : ($tipo === 'submenu' ? 'tbl_sub_bot' : 'tbl_opc_bot'); // ✅ AGREGADO soporte para opciones

        $campo = $tipo === 'menu' ? 'men_id' : ($tipo === 'submenu' ? 'sub_id' : 'opc_id'); // ✅ CORREGIDO

        $valor = $tipo === 'menu' ? $menId : ($tipo === 'submenu' ? $subId : $opcId); // ✅ CORREGIDO

        $botonesConfigurados = DB::table($tabla)
            ->where($campo, $valor)
            ->where(str_replace('tbl_', '', $tabla) . '_activo', true)
            ->count();

        $botonesConPermiso = DB::table('tbl_perm_bot_perfil')
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->where('perm_bot_per_activo', true)
            ->count();

        return [
            'tiene_acceso' => $tienePermisoProfile,
            'botones_configurados' => $botonesConfigurados,
            'botones_con_permiso' => $botonesConPermiso
        ];
    }

    private function otorgarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo)
    {
        // 1. Crear permiso de perfil
        DB::table('tbl_perm_perfil')->updateOrInsert(
            [
                'per_id' => $perfilId,
                'men_id' => $menId,
                'sub_id' => $subId,
                'opc_id' => $opcId
            ],
            [
                'perm_per_activo' => true,
                'perm_per_edi' => now()
            ]
        );

        // 2. Asegurar que tiene botones asignados
        $this->asegurarBotonesAsignados($menId, $subId, $opcId, $tipo);

        // 3. Crear permisos de botones
        $tabla = $tipo === 'menu' ? 'tbl_men_bot' : ($tipo === 'submenu' ? 'tbl_sub_bot' : 'tbl_opc_bot');
        $campo = $tipo === 'menu' ? 'men_id' : ($tipo === 'submenu' ? 'sub_id' : 'opc_id');
        $valor = $tipo === 'menu' ? $menId : ($tipo === 'submenu' ? $subId : $opcId);

        $botones = DB::table($tabla)
            ->where($campo, $valor)
            ->where(str_replace('tbl_', '', $tabla) . '_activo', true)
            ->get();

        $permisosCreados = 0;
        foreach ($botones as $boton) {
            $inserted = DB::table('tbl_perm_bot_perfil')->updateOrInsert(
                [
                    'per_id' => $perfilId,
                    'men_id' => $menId,
                    'sub_id' => $subId,
                    'opc_id' => $opcId,
                    'bot_id' => $boton->bot_id
                ],
                [
                    'perm_bot_per_activo' => true,
                    'perm_bot_per_edi' => now()
                ]
            );
            if ($inserted) $permisosCreados++;
        }

        return [
            'message' => 'Acceso completo otorgado correctamente',
            'data' => [
                'permisos_creados' => $permisosCreados,
                'botones_asignados' => $botones->count()
            ]
        ];
    }

    private function revocarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo)
    {
        // Eliminar permisos de botones
        $botonesRevocados = DB::table('tbl_perm_bot_perfil')
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->delete();

        // Eliminar permiso de perfil
        $perfilRevocado = DB::table('tbl_perm_perfil')
            ->where('per_id', $perfilId)
            ->where('men_id', $menId)
            ->where('sub_id', $subId)
            ->where('opc_id', $opcId)
            ->delete();

        return [
            'message' => 'Acceso revocado correctamente',
            'data' => [
                'permisos_revocados' => $botonesRevocados + $perfilRevocado
            ]
        ];
    }

    private function asegurarBotonesAsignados($menId, $subId, $opcId, $tipo)
    {
        try {
            switch ($tipo) {
                case 'menu':
                    $tabla = 'tbl_men_bot';
                    $campoId = 'men_id';
                    $valorId = $menId;
                    $camposInsercion = [
                        'men_id' => $valorId,
                        'men_bot_orden' => 0,
                        'men_bot_activo' => true,
                        'men_bot_cre' => now(),
                        'men_bot_edi' => now()
                    ];
                    break;

                case 'submenu':
                    $tabla = 'tbl_sub_bot';
                    $campoId = 'sub_id';
                    $valorId = $subId;
                    $camposInsercion = [
                        'sub_id' => $valorId,
                        'sub_bot_orden' => 0,
                        'sub_bot_activo' => true,
                        'sub_bot_cre' => now(),
                        'sub_bot_edi' => now()
                    ];
                    break;

                case 'opcion':
                    $tabla = 'tbl_opc_bot';
                    $campoId = 'opc_id';
                    $valorId = $opcId;
                    // ✅ CORREGIDO: Usar los campos exactos de la tabla tbl_opc_bot
                    $camposInsercion = [
                        'opc_id' => $valorId,
                        'opc_bot_requerido' => false,  // Campo que existe en la tabla
                        'opc_bot_orden' => 0,          // Campo que existe en la tabla
                        'opc_bot_activo' => true,      // Campo que existe en la tabla
                        'opc_bot_cre' => now()         // Campo que existe en la tabla
                        // ❌ NO incluir opc_bot_edi porque no existe en la tabla
                    ];
                    break;

                default:
                    throw new \Exception("Tipo de módulo no válido: {$tipo}");
            }

            // Verificar si ya tiene botones
            $tieneBotones = DB::table($tabla)->where($campoId, $valorId)->exists();

            if (!$tieneBotones) {
                Log::info("🔧 Asignando botones básicos para {$tipo} ID: {$valorId}");

                // Asignar botones básicos CRUD
                $botones = DB::table('tbl_bot')
                    ->where('bot_activo', true)
                    ->whereIn('bot_codigo', ['CREATE', 'READ', 'UPDATE', 'DELETE', 'EXPORT'])
                    ->orderBy('bot_orden')
                    ->get();

                $botonesInsertados = 0;
                foreach ($botones as $index => $boton) {
                    try {
                        $datosInsercion = $camposInsercion;
                        $datosInsercion['bot_id'] = $boton->bot_id;

                        // Actualizar orden específico para cada botón
                        if (isset($datosInsercion['men_bot_orden'])) {
                            $datosInsercion['men_bot_orden'] = $index + 1;
                        } elseif (isset($datosInsercion['sub_bot_orden'])) {
                            $datosInsercion['sub_bot_orden'] = $index + 1;
                        } elseif (isset($datosInsercion['opc_bot_orden'])) {
                            $datosInsercion['opc_bot_orden'] = $index + 1;
                        }

                        DB::table($tabla)->insert($datosInsercion);
                        $botonesInsertados++;

                        Log::info("✅ Botón insertado: {$boton->bot_codigo} para {$tipo}");
                    } catch (\Exception $e) {
                        Log::error("❌ Error insertando botón {$boton->bot_codigo}: " . $e->getMessage());
                        // Continuar con el siguiente botón
                    }
                }

                Log::info("✅ Total botones insertados para {$tipo}: {$botonesInsertados}");
            } else {
                Log::info("ℹ️ {$tipo} ID {$valorId} ya tiene botones asignados");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error en asegurarBotonesAsignados para {$tipo}: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function getTodosLosModulosDirectos()
    {
        $modulos = [];

        // 1. Menús directos
        $menus = DB::table('tbl_men')
            ->where('men_ventana_directa', true)
            ->where('men_activo', true)
            ->get();

        foreach ($menus as $menu) {
            $modulos[] = [
                'men_id' => $menu->men_id,
                'sub_id' => null,
                'opc_id' => null,
                'tipo' => 'menu',
                'nombre' => $menu->men_nom
            ];
        }

        // 2. Submenús directos
        $submenus = DB::table('tbl_sub')
            ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->where('tbl_sub.sub_ventana_directa', true)
            ->where('tbl_sub.sub_activo', true)
            ->get();

        foreach ($submenus as $submenu) {
            $modulos[] = [
                'men_id' => $submenu->men_id,
                'sub_id' => $submenu->sub_id,
                'opc_id' => null,
                'tipo' => 'submenu',
                'nombre' => $submenu->sub_nom
            ];
        }

        // 3. ✅ NUEVO: Opciones directas
        $opciones = DB::table('tbl_opc')
            ->join('tbl_sub_opc', 'tbl_opc.opc_id', '=', 'tbl_sub_opc.opc_id')
            ->join('tbl_sub', 'tbl_sub_opc.sub_id', '=', 'tbl_sub.sub_id')
            ->join('tbl_men_sub', 'tbl_sub.sub_id', '=', 'tbl_men_sub.sub_id')
            ->where('tbl_opc.opc_ventana_directa', true)
            ->where('tbl_opc.opc_activo', true)
            ->select('tbl_opc.*', 'tbl_sub.sub_id', 'tbl_men_sub.men_id')
            ->get();

        foreach ($opciones as $opcion) {
            $modulos[] = [
                'men_id' => $opcion->men_id,
                'sub_id' => $opcion->sub_id,
                'opc_id' => $opcion->opc_id,
                'tipo' => 'opcion',
                'nombre' => $opcion->opc_nom
            ];
        }

        return $modulos;
    }

    private function otorgarAccesoSoloLectura($perfilId, $menId, $subId, $opcId, $tipo)
    {
        // Similar a otorgarAccesoCompleto pero solo con botón READ
        // Implementar según necesidades
        return $this->otorgarAccesoCompleto($perfilId, $menId, $subId, $opcId, $tipo);
    }
}
