<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ButtonPermissionProfile extends Model
{
    protected $table = 'tbl_perm_bot_perfil';
    protected $primaryKey = 'perm_bot_per_id';
    public $timestamps = true;
    
    const CREATED_AT = 'perm_bot_per_cre';
    const UPDATED_AT = 'perm_bot_per_edi';

    protected $fillable = [
        'per_id',
        'men_id',
        'sub_id',
        'opc_id',
        'bot_id',
        'perm_bot_per_activo'
    ];

    protected $casts = [
        'perm_bot_per_activo' => 'boolean'
    ];

    // Relación con perfil
    public function perfil()
    {
        return $this->belongsTo(Perfil::class, 'per_id', 'per_id');
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

    // Scope para permisos activos
    public function scopeActive($query)
    {
        return $query->where('perm_bot_per_activo', true);
    }
}