<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {


    $startDate = Carbon::parse("2024-08-01")->toDateString();
    $endDate = Carbon::parse("2024-08-12")->toDateString();

    $data = DB::table('logs')
        ->select('stock_product_id', 'created_at', DB::raw("
    CASE
        WHEN DATE(created_at) >= '{$startDate}' AND DATE(created_at) <= '{$endDate}' THEN
            quantity_first
         ELSE
          0
    END as quantity_first
"))

        ->orderBy('created_at', 'asc')
        ->get();

    return response()->json(json_decode(json_encode($data), true));
});
