<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Opcion extends Model
{
    protected $table = 'tbl_opc';
    protected $primaryKey = 'opc_id';
    public $timestamps = false;
    
    protected $fillable = ['opc_nom', 'opc_eje'];
    
    public function submenus()
    {
        return $this->belongsToMany(
            Submenu::class, 
            'tbl_sub_opc', 
            'opc_id', 
            'sub_id'
        );
    }
    public function botones()
{
    return $this->belongsToMany(
        Button::class, 
        'tbl_opc_bot', 
        'opc_id', 
        'bot_id'
    )->withPivot(['opc_bot_requerido', 'opc_bot_orden', 'opc_bot_activo'])
     ->wherePivot('opc_bot_activo', true);
}

// Relación con permisos de botones de perfiles
public function permisosBotonPerfil()
{
    return $this->hasMany(ButtonPermissionProfile::class, 'opc_id', 'opc_id');
}

// Relación con permisos de botones de usuarios
public function permisosBotonUsuario()
{
    return $this->hasMany(ButtonPermissionUser::class, 'opc_id', 'opc_id');
}
}