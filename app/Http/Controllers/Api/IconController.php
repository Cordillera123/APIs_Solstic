<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IconController extends Controller
{
    /**
     * Obtener todos los iconos disponibles
     */
    public function index()
    {
        $icons = DB::table('tbl_ico')
            ->select('ico_id as id', 'ico_nom as nombre', 'ico_lib as libreria', 'ico_cat as categoria', 'ico_pro as propiedades', 'ico_des as descripcion')
            ->orderBy('ico_nom')
            ->get();
            
        return response()->json([
            'status' => 'success',
            'data' => $icons
        ]);
    }
    
    /**
     * Obtener iconos por categoría
     */
    public function getByCategory($category)
    {
        $icons = DB::table('tbl_ico')
            ->select('ico_id as id', 'ico_nom as nombre', 'ico_lib as libreria', 'ico_pro as propiedades', 'ico_des as descripcion')
            ->where('ico_cat', $category)
            ->orderBy('ico_nom')
            ->get();
            
        return response()->json([
            'status' => 'success',
            'data' => $icons
        ]);
    }
}