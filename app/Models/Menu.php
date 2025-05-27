<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $table = 'tbl_men';
    protected $primaryKey = 'men_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;
    
    protected $fillable = [
        'men_nom',
        'ico_id',
        'men_componente',
        'men_eje',
        'men_ventana_directa',
        'men_est'
    ];
    
    protected $casts = [
        'men_ventana_directa' => 'boolean',
        'men_est' => 'boolean',
    ];
}