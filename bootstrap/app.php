<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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

        // ✅ REGISTRAR MIDDLEWARE DE HORARIOS CON VERIFICACIÓN
        $middleware->alias([
            'horario' => \App\Http\Middleware\VerificarHorarioOficina::class,
            'horario-individual' => \App\Http\Middleware\VerificarHorarioIndividual::class
        ]);

        // ✅ NO USAR THROTTLING POR AHORA PARA EVITAR ERRORES
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ✅ MANEJO PERSONALIZADO DE EXCEPCIONES PARA APIs
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No autenticado',
                    'error_code' => 'UNAUTHENTICATED'
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No autorizado',
                    'error_code' => 'UNAUTHORIZED'
                ], 403);
            }
        });

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

        // ✅ MANEJO DE ERRORES GENERALES PARA EVITAR EL ERROR DE CSS
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson()) {
                \Illuminate\Support\Facades\Log::error('API Error: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => app()->environment('production') 
                        ? 'Error interno del servidor' 
                        : $e->getMessage(),
                    'error_code' => 'INTERNAL_SERVER_ERROR'
                ], 500);
            }
        });
    })->create();