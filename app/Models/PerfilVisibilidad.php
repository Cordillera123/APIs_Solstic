<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerfilVisibilidad extends Model
{
    protected $table = 'tbl_perm_perfil_visibilidad';
    protected $primaryKey = 'perm_per_vis_id';
    
    const CREATED_AT = 'perm_per_vis_cre';
    const UPDATED_AT = 'perm_per_vis_edi';
    
    protected $fillable = [
        'usu_id',
        'per_id_visible', 
        'perm_per_vis_activo',
        'perm_per_vis_creado_por'
    ];
    
    protected $casts = [
        'perm_per_vis_activo' => 'boolean',
    ];
}