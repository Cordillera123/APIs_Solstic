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
}