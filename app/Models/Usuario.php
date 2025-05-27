<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'tbl_usu';
    protected $primaryKey = 'usu_id';
    
    protected $fillable = [
        'usu_nom', 'usu_nom2', 'usu_ape', 'usu_ape2', 'usu_cor', 
        'usu_ced', 'usu_con', 'usu_tel', 'usu_dir', 'per_id', 'est_id',
        'usu_descripcion', 'usu_fecha_nacimiento', 'usu_fecha_registro',
        'usu_fecha_actualizacion_clave', 'usu_fecha_cambio_clave',
        'usu_deshabilitado', 'usu_clave_hasheada', 'usu_nombre_encriptado',
        'usu_ultimo_acceso', 'usu_intentos_fallidos', 'usu_bloqueado_hasta',
        'usu_cre', 'usu_edi', 'usu_creado_por', 'usu_editado_por'
    ];

    protected $hidden = [
        'usu_con', 'usu_clave_hasheada'
    ];

    public $timestamps = false;

    // Relaciones
    public function perfil()
    {
        return $this->belongsTo(Perfil::class, 'per_id', 'per_id');
    }

    public function estado()
    {
        return $this->belongsTo(Estado::class, 'est_id', 'est_id');
    }

    // Redefinir campos para Auth
    public function getAuthPassword()
    {
        return $this->usu_con;
    }

    public function getEmailForPasswordReset()
    {
        return $this->usu_cor;
    }

    public function username()
    {
        return 'usu_cor';
    }
}