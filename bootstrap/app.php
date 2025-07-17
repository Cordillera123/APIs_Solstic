<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ✅ EXCLUIR RUTAS API DEL CSRF
        $middleware->validateCsrfTokens(except: [
            'api/*'
        ]);

        // ✅ REGISTRAR TODOS LOS MIDDLEWARES
        $middleware->alias([
            // Middleware de permisos
            'permissions' => \App\Http\Middleware\CheckPermissions::class,
            
            // Middlewares de horario
            'horario.oficina' => \App\Http\Middleware\VerificarHorarioOficina::class,
            'horario.individual' => \App\Http\Middleware\VerificarHorarioIndividual::class,
            
            // Alias compatibles con versiones anteriores
            'horario' => \App\Http\Middleware\VerificarHorarioOficina::class,
            'horario-individual' => \App\Http\Middleware\VerificarHorarioIndividual::class,
        ]);

        // ✅ CONFIGURAR GRUPO DE MIDDLEWARE PARA API
        $middleware->group('api', [
            // Sanctum para autenticación API
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            
            // Throttling DESACTIVADO temporalmente
            // 'throttle:api',
            
            // Binding de modelos
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // ✅ CONFIGURAR REDIRECCIÓN DE HUÉSPEDES PARA APIS
        $middleware->redirectGuestsTo(function ($request) {
            // Si es una petición API, devolver JSON
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No autenticado',
                    'error_code' => 'UNAUTHENTICATED'
                ], 401);
            }
            // Para peticiones web (si las hay)
            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ✅ FUNCIÓN AUXILIAR PARA OBTENER USER ID SEGURO
        $getSafeUserId = function() {
            try {
                return Auth::check() ? Auth::id() : 'guest';
            } catch (\Throwable $e) {
                return 'guest';
            }
        };

        // ✅ MANEJO ESPECÍFICO DE ERRORES DE AUTENTICACIÓN
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No autenticado',
                    'error_code' => 'UNAUTHENTICATED',
                    'debe_cerrar_sesion' => true
                ], 401);
            }
        });

        // ✅ MANEJO ESPECÍFICO DE ERRORES DE AUTORIZACIÓN
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No autorizado para realizar esta acción',
                    'error_code' => 'UNAUTHORIZED'
                ], 403);
            }
        });

        // ✅ MANEJO DE ERRORES DE VALIDACIÓN
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Errores de validación',
                    'errors' => $e->errors(),
                    'error_code' => 'VALIDATION_ERROR'
                ], 422);
            }
        });

        // ✅ MANEJO ESPECÍFICO DE ERRORES DE CLASE NO ENCONTRADA (MIDDLEWARE)
        $exceptions->render(function (\Error $e, $request) use ($getSafeUserId) {
            if ($request->expectsJson() && 
                (str_contains($e->getMessage(), 'Class') && str_contains($e->getMessage(), 'not found')) ||
                str_contains($e->getMessage(), 'Middleware')
            ) {
                \Illuminate\Support\Facades\Log::error('Middleware Class Error: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'url' => $request->url(),
                    'method' => $request->method(),
                    'user_agent' => $request->userAgent(),
                    'ip' => $request->ip(),
                    'user_id' => $getSafeUserId(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de configuración del middleware',
                    'error_code' => 'MIDDLEWARE_ERROR',
                    'debug_info' => app()->environment('production') ? null : [
                        'class_error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]
                ], 500);
            }
        });

        // ✅ MANEJO DE ERRORES DE BASE DE DATOS
        $exceptions->render(function (\Illuminate\Database\QueryException $e, $request) use ($getSafeUserId) {
            if ($request->expectsJson()) {
                \Illuminate\Support\Facades\Log::error('Database Error: ' . $e->getMessage(), [
                    'sql' => $e->getSql(),
                    'bindings' => $e->getBindings(),
                    'url' => $request->url(),
                    'method' => $request->method(),
                    'user_id' => $getSafeUserId()
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => app()->environment('production') 
                        ? 'Error en la base de datos' 
                        : 'Database Error: ' . $e->getMessage(),
                    'error_code' => 'DATABASE_ERROR'
                ], 500);
            }
        });

        // ✅ MANEJO DE ERRORES GENERALES - FORZAR JSON PARA APIS
        $exceptions->render(function (\Throwable $e, $request) use ($getSafeUserId) {
            // Forzar JSON para todas las rutas API
            if ($request->is('api/*') || $request->expectsJson()) {
                \Illuminate\Support\Facades\Log::error('API Error: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'url' => $request->url(),
                    'method' => $request->method(),
                    'user_id' => $getSafeUserId(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => app()->environment('production') 
                        ? 'Error interno del servidor' 
                        : $e->getMessage(),
                    'error_code' => 'INTERNAL_SERVER_ERROR',
                    'debug_info' => app()->environment('production') ? null : [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => substr($e->getTraceAsString(), 0, 1000) // Limitar trace
                    ]
                ], 500);
            }
        });
    })->create();