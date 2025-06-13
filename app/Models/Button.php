<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Button extends Model
{
    protected $table = 'tbl_bot';
    protected $primaryKey = 'bot_id';
    public $timestamps = false;
    
    protected $fillable = [
        'bot_nom',
        'bot_codigo', 
        'ico_id',
        'bot_color',
        'bot_tooltip',
        'bot_confirmacion',
        'bot_mensaje_confirmacion',
        'bot_orden',
        'bot_activo'
    ];

    protected $casts = [
        'bot_confirmacion' => 'boolean',
        'bot_activo' => 'boolean',
        'bot_orden' => 'integer'
    ];

    // *** RELACIÓN CON ICONOS COMENTADA - MANEJADA EN FRONTEND ***
    // public function icono()
    // {
    //     return $this->belongsTo(Icon::class, 'ico_id', 'ico_id');
    // }

    // Relación con opciones que usan este botón
    public function opciones()
    {
        return $this->belongsToMany(
            Opcion::class, 
            'tbl_opc_bot', 
            'bot_id', 
            'opc_id'
        )->withPivot(['opc_bot_requerido', 'opc_bot_orden', 'opc_bot_activo']);
    }

    // Relación con permisos de perfiles
    public function permisosPerfiles()
    {
        return $this->hasMany(ButtonPermissionProfile::class, 'bot_id', 'bot_id');
    }

    // Relación con permisos de usuarios
    public function permisosUsuarios()
    {
        return $this->hasMany(ButtonPermissionUser::class, 'bot_id', 'bot_id');
    }

    // Scope para botones activos
    public function scopeActive($query)
    {
        return $query->where('bot_activo', true);
    }

    // Scope para ordenar por orden
    public function scopeOrdered($query)
    {
        return $query->orderBy('bot_orden');
    }

    // Método para obtener el icono por defecto según el código del botón
    public function getDefaultIcon()
    {
        $iconos = [
            'CREATE' => 'plus',
            'READ' => 'eye',
            'UPDATE' => 'edit',
            'DELETE' => 'trash',
            'EXPORT' => 'download',
            'PRINT' => 'printer',
            'DUPLICATE' => 'copy',
            'TOGGLE' => 'toggle-on'
        ];

        return $iconos[$this->bot_codigo] ?? 'circle';
    }
}