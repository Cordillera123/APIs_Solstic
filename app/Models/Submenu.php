<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submenu extends Model
{
    protected $table = 'tbl_sub';
    protected $primaryKey = 'sub_id';
    public $timestamps = false;
    
    protected $fillable = ['sub_nom', 'sub_eje'];
    
    public function menus()
    {
        return $this->belongsToMany(
            Menu::class, 
            'tbl_men_sub', 
            'sub_id', 
            'men_id'
        );
    }
    
    public function opciones()
    {
        return $this->belongsToMany(
            Opcion::class, 
            'tbl_sub_opc', 
            'sub_id', 
            'opc_id'
        );
    }
}