<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ButtonPermissionUser extends Model
{
    protected $table = 'tbl_perm_bot_usuario';
    protected $primaryKey = 'perm_bot_usu_id';
    public $timestamps = true;
    
    const CREATED_AT = 'perm_bot_usu_cre';
    const UPDATED_AT = 'perm_bot_usu_edi';

    protected $fillable = [
        'usu_id',
        'men_id',
        'sub_id',
        'opc_id',
        'bot_id',
        'perm_tipo',
        'perm_bot_usu_observaciones',
        'perm_bot_usu_fecha_inicio',
        'perm_bot_usu_fecha_fin',
        'perm_bot_usu_activo',
        'perm_bot_usu_creado_por'
    ];

    protected $casts = [
        'perm_bot_usu_activo' => 'boolean',
        'perm_bot_usu_fecha_inicio' => 'date',
        'perm_bot_usu_fecha_fin' => 'date'
    ];

    // Relación con usuario
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usu_id', 'usu_id');
    }

    // Relación con menú
    public function menu()
    {
        return $this->belongsTo(Menu::class, 'men_id', 'men_id');
    }

    // Relación con submenú
    public function submenu()
    {
        return $this->belongsTo(Submenu::class, 'sub_id', 'sub_id');
    }

    // Relación con opción
    public function opcion()
    {
        return $this->belongsTo(Opcion::class, 'opc_id', 'opc_id');
    }

    // Relación con botón
    public function boton()
    {
        return $this->belongsTo(Button::class, 'bot_id', 'bot_id');
    }

    // Relación con usuario que creó el permiso
    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'perm_bot_usu_creado_por', 'usu_id');
    }

    // Scope para permisos activos
    public function scopeActive($query)
    {
        return $query->where('perm_bot_usu_activo', true);
    }

    // Scope para permisos vigentes (dentro de fechas)
    public function scopeVigente($query)
    {
        return $query->where(function($q) {
            $q->whereNull('perm_bot_usu_fecha_inicio')
              ->orWhere('perm_bot_usu_fecha_inicio', '<=', now());
        })->where(function($q) {
            $q->whereNull('perm_bot_usu_fecha_fin')
              ->orWhere('perm_bot_usu_fecha_fin', '>=', now());
        });
    }

    // Scope para permisos concedidos
    public function scopeConcedidos($query)
    {
        return $query->where('perm_tipo', 'C');
    }

    // Scope para permisos denegados
    public function scopeDenegados($query)
    {
        return $query->where('perm_tipo', 'D');
    }
}
