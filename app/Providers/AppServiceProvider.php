<?php

namespace App\Providers;

use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Usuario::creating(function ($usuario) {
            if (isset($usuario->usu_con)) {
                $usuario->usu_con = Hash::make($usuario->usu_con);
            }
        });

        Usuario::updating(function ($usuario) {
            if ($usuario->isDirty('usu_con') && !Hash::check($usuario->usu_con, $usuario->getOriginal('usu_con'))) {
                $usuario->usu_con = Hash::make($usuario->usu_con);
            }
        });
    }
}