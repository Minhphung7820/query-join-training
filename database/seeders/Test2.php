<?php

namespace App\Module\Warehouse\Repository;

use App\Helpers\Activity;
use App\Module\CRM\Model\Product;
use App\Module\CRM\Model\StockProduct;
use App\Module\Warehouse\Model\Brands;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Package\Util\BasicEntity;
use Package\Repository\RepositoryInterface;
use Exception;
use Illuminate\Support\Facades\Auth;
use Package\Exception\DatabaseException;
use Package\Exception\HttpException;
use Maatwebsite\Excel\Facades\Excel;

class ImportExportInventoryRepository extends BasicEntity implements RepositoryInterface
{
    public $table;

    public $primaryKey;

    public $fillable;

    public $hidden;

    public function __construct()
    {
        $brand = new Brands();
        $this->table = $brand->getTable();
        $this->fillable = $brand->getFillable();
        $this->hidden = $brand->getHidden();
        $this->primaryKey = $brand->getKeyName();
        array_push($this->fillable, $this->primaryKey);
    }

    public function all($request)
    {
        try {
            $business_id = request()->header('business-id');
            //
            $startDate = $request->start_date ? Carbon::parse($request->start_date) : null;
            $endDate = $request->end_date ? Carbon::parse($request->end_date) : null;
            //
            if ($endDate && $startDate && $endDate->lt($startDate)) {
                throw new HttpException('Ngày kết thúc không thể nhỏ hơn ngày bắt đầu.');
            }
            //
            $keyword = $request->keyword ? trim($request->keyword) : null; //
            //
            $fromQuantityOut = $request->from_quantity_out ? trim($request->from_quantity_out) : null; //
            $toQuantityOut = $request->to_quantity_out ? trim($request->to_quantity_out) : null; //
            $fromQuantityIn = $request->from_quantity_in ? trim($request->from_quantity_in) : null; //
            $toQuantityIn = $request->to_quantity_in ? trim($request->to_quantity_in) : null; //
            //
            $stockId = $request->stock_id ? trim($request->stock_id) : null;
            //
            $fromQuantityFirst = $request->from_quantity_first ? trim($request->from_quantity_first) : null; //
            $toQuantityFirst = $request->to_quantity_first ? trim($request->to_quantity_first) : null; //
            //
            $fromQuantityEnd = $request->from_quantity_end ? trim($request->from_quantity_end) : null; //
            $toQuantityEnd = $request->to_quantity_end ? trim($request->to_quantity_end) : null; //
            //
            $list = StockProduct::leftJoin('products', 'products.id', '=', 'stock_products.product_id')
                ->leftJoin('stocks', 'stocks.id', '=', 'stock_products.stock_id')
                ->leftJoin('crm_variant_attributes', 'crm_variant_attributes.id', '=', 'stock_products.attribute_first_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->leftJoinSub(DB::table('crm_qty_stock_logs')
                    ->select(
                        'stock_product_id',
                        DB::raw('SUM(CASE WHEN type_action = "sub" THEN quantity_out ELSE 0 END) as total_quantity_out'),
                        DB::raw('SUM(CASE WHEN type_action = "add" THEN quantity_in ELSE 0 END) as total_quantity_in')
                    )
                    ->where('business_id', $business_id)
                    ->whereNotNull('stock_product_id')
                    ->when(!is_null($startDate), function ($query) use ($startDate) {
                        return $query->whereDate('created_at', '>=', $startDate);
                    })
                    ->when(!is_null($endDate), function ($query) use ($endDate) {
                        return $query->whereDate('created_at', '<=', $endDate);
                    })
                    ->groupBy('stock_product_id'), 'logs_quantity', function ($join) {
                    $join->on('stock_products.id', '=', 'logs_quantity.stock_product_id');
                });
            //
            $getTableFirstQty = DB::table('crm_qty_stock_logs')
                ->select('stock_product_id', 'id', 'created_at as created_at_first_old', 'quantity_first')
                ->where('business_id', $business_id)
                ->whereNotNull('stock_product_id')
                ->when(!is_null($startDate), function ($query) use ($startDate) {
                    return $query->whereDate('created_at', '>=', $startDate);
                })
                ->groupBy('stock_product_id')
                ->orderBy('id', 'asc');

            $getMaxLog = DB::table('crm_qty_stock_logs as logs')
                ->joinSub(
                    DB::table('crm_qty_stock_logs')
                        ->select('stock_product_id', DB::raw('MAX(id) as max_id'))
                        ->where('business_id', $business_id)
                        ->whereNotNull('stock_product_id')
                        ->groupBy('stock_product_id'),
                    'max_logs',
                    function ($join) {
                        $join->on('logs.stock_product_id', '=', 'max_logs.stock_product_id')
                            ->on('logs.id', '=', 'max_logs.max_id');
                    }
                )
                ->select('logs.stock_product_id', 'logs.id', 'logs.quantity_end', 'logs.created_at')
                ->orderBy('logs.id', 'desc');

            $getTableEndQty = DB::table('crm_qty_stock_logs')
                ->select('stock_product_id', 'id', 'created_at as created_at_end_new', 'quantity_end')
                ->where('business_id', $business_id)
                ->whereNotNull('stock_product_id')
                ->when(!is_null($endDate), function ($query) use ($endDate) {
                    return $query->whereDate('created_at', '=', $endDate);
                })
                ->groupBy('stock_product_id')
                ->orderBy('id', 'desc');
            // Subquery to get quantity_first from the oldest record
            $list->leftJoinSub(
                DB::table('crm_qty_stock_logs')
                    ->where('crm_qty_stock_logs.business_id', $business_id)
                    ->leftJoinSub($getTableFirstQty, 'get_first', function ($join) {
                        $join->on('crm_qty_stock_logs.id', '=', 'get_first.id');
                    })
                    ->leftJoinSub($getTableEndQty, 'get_end', function ($join) {
                        $join->on('crm_qty_stock_logs.id', '=', 'get_end.id');
                    })
                    ->leftJoinSub($getMaxLog, 'max_log', function ($join) {
                        $join->on('crm_qty_stock_logs.id', '=', 'max_log.id');
                    })
                    ->whereNotNull('crm_qty_stock_logs.stock_product_id')
                    ->select(
                        'crm_qty_stock_logs.stock_product_id',
                        DB::raw('
            CASE
                WHEN DATE(max_log.created_at) < "' . $startDate->toDateString() . '"
                THEN max_log.quantity_end
                ELSE get_first.quantity_first
            END as quantity_first
        '),
                        'get_end.quantity_end',
                        DB::raw('
            CASE
                WHEN DATE(max_log.created_at) < "' . $startDate->toDateString() . '"
                THEN max_log.id
                ELSE get_first.id
            END as log_id
        ')
                    ),
                'oldest_log',
                function ($join) {
                    $join->on('stock_products.id', '=', 'oldest_log.stock_product_id');
                }
            );

            $list->where('products.business_id', $business_id)
                ->whereNotNull('oldest_log.log_id')
                ->where(function ($query) {
                    $query->where(function ($q) {
                        $q->where('products.type', '!=', 'variable')
                            ->where('stock_products.is_main_stock', 1);
                    })->orWhere(function ($q) {
                        $q->where('products.type', '=', 'variable')
                            ->where('stock_products.is_main_stock', 0);
                    });
                });
            // search sku
            $list->when(isset($keyword) && $keyword !== "", function ($query) use ($keyword) {
                return $query->where(function ($q) use ($keyword) {
                    $q->where('products.sku', 'LIKE', "%$keyword%");
                    $q->orWhere('stock_products.sku', 'LIKE', "%$keyword%");
                    $q->orWhere('products.name', 'LIKE', "%$keyword%");
                });
            });
            // Filter by total_quantity_out range
            $list->when(isset($fromQuantityOut) && $fromQuantityOut !== "", function ($query) use ($fromQuantityOut) {
                return $query->whereRaw('COALESCE(total_quantity_out, 0) >= ?', [(int)$fromQuantityOut]);
            });

            $list->when(isset($toQuantityOut) && $fromQuantityOut !== "", function ($query) use ($toQuantityOut) {
                return $query->whereRaw('COALESCE(total_quantity_out, 0) <= ?', [(int)$toQuantityOut]);
            });
            // Filter by total_quantity_in range
            $list->when(isset($fromQuantityIn) && $fromQuantityIn !== "", function ($query) use ($fromQuantityIn) {
                return $query->whereRaw('COALESCE(total_quantity_in, 0) >= ?', [(int)$fromQuantityIn]);
            });

            $list->when(isset($toQuantityIn) && $toQuantityIn !== "", function ($query) use ($toQuantityIn) {
                return $query->whereRaw('COALESCE(total_quantity_in, 0) <= ?', [(int)$toQuantityIn]);
            });

            // Filter by stock_products.stock_id range
            $list->when(isset($stockId) && $stockId !== "", function ($query) use ($stockId) {
                return $query->where('stock_products.stock_id', $stockId);
            });
            // Filter by quantity first range
            $list->when(isset($fromQuantityFirst) && $fromQuantityFirst !== "", function ($query) use ($fromQuantityFirst) {
                return $query->whereRaw('COALESCE(oldest_log.quantity_first, 0) >= ?', [(int)$fromQuantityFirst]);
            });

            $list->when(isset($toQuantityFirst) && $toQuantityFirst !== "", function ($query) use ($toQuantityFirst) {
                return $query->whereRaw('COALESCE(oldest_log.quantity_first, 0) <= ?', [(int)$toQuantityFirst]);
            });
            // Filter by quantity_end range
            $list->when(isset($fromQuantityEnd) && $fromQuantityEnd !== "", function ($query) use ($fromQuantityEnd) {
                return $query->having('quantity_end', '>=', $fromQuantityEnd);
            });

            $list->when(isset($toQuantityEnd) && $toQuantityEnd !== "", function ($query) use ($toQuantityEnd) {
                return $query->having('quantity_end', '<=', $toQuantityEnd);
            });
            //
            $list->select([
                'products.id',
                'products.name',
                'stocks.stock_name',
                'stock_products.stock_id',
                'stock_products.attribute_first_id',
                'stock_products.id as variant_id',
                'categories.name as category_name',
                DB::raw('CASE WHEN products.type = "variable" THEN stock_products.sku ELSE products.sku END as sku'),
                'crm_variant_attributes.title as attribute_first_title',
                'products.type',
                'oldest_log.log_id',
                DB::raw('COALESCE(total_quantity_out, 0) as total_quantity_out'),
                DB::raw('COALESCE(total_quantity_in, 0) as total_quantity_in'),
                DB::raw('COALESCE(oldest_log.quantity_first, 0) as quantity_first'),
                DB::raw('COALESCE(oldest_log.quantity_first, 0) + COALESCE(total_quantity_in, 0) - COALESCE(total_quantity_out, 0) as quantity_end') // tính toán quantity_end
            ]);

            $sort_field = "products.created_at";
            $sort_des = "desc";

            if (isset($request->order_field) && $request->order_field) {
                $sort_field = $request->order_field;
            }

            if (isset($request->order_by) && $request->order_by) {
                $sort_des = $request->order_by;
            }

            $list->orderBy($sort_field, $sort_des);

            return $list->paginate($request->limit ?? 10);
        } catch (HttpException $e) {
            $message = $e->getMessage();

            throw new HttpException($message, 500, []);
        }
    }

    public function history($request)
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date) : null;
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : null;
        return DB::table('crm_qty_stock_logs as logs')
            ->leftJoin('transactions', 'transactions.id', '=', 'logs.transaction_id')
            ->leftJoin('crm_customers', 'crm_customers.id', '=', 'transactions.contact_id')
            ->leftJoin('crm_transfer_warehouse', 'crm_transfer_warehouse.id', '=', 'logs.transfer_Warehouse_id')
            ->where('logs.business_id', request()->header('business-id'))
            ->when(isset($request['stock_product_id']) && $request['stock_product_id'], function ($query) use ($request) {
                return $query->where('logs.stock_product_id', $request['stock_product_id']);
            })
            ->when(!is_null($startDate), function ($query) use ($startDate) {
                return $query->whereDate('logs.created_at', '>=', $startDate);
            })
            ->when(!is_null($endDate), function ($query) use ($endDate) {
                return $query->whereDate('logs.created_at', '<=', $endDate);
            })
            ->when(isset($request['keyword']) && $request['keyword'] !== "", function ($query) use ($request) {
                $keyword = trim($request['keyword']);
                return $query->where(function ($subQuery) use ($keyword) {
                    $subQuery->where('transactions.invoice_no', 'LIKE', "%$keyword%");
                    $subQuery->orWhere('crm_transfer_warehouse.code_warehouse_transfer', 'LIKE', "%$keyword%");
                    $subQuery->orWhere('crm_customers.business_name', 'LIKE', "%$keyword%");
                    $subQuery->orWhere('crm_customers.code', 'LIKE', "%$keyword%");
                });
            })
            ->select([
                'logs.id',
                'logs.created_at',
                'logs.type',
                DB::raw('CASE WHEN logs.type = "transfer" THEN crm_transfer_warehouse.code_warehouse_transfer ELSE transactions.invoice_no END as invoice_no'),
                'crm_customers.business_name',
                'logs.quantity_first',
                'logs.quantity_end',
                'logs.quantity_in',
                'logs.quantity_out'
            ])
            ->orderBy('logs.id', 'asc')
            ->paginate($request['limit'] ?? 10);
    }

    private function getRealSql($query)
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        foreach ($bindings as $binding) {
            // Chuyển đổi giá trị thành dạng chuỗi an toàn cho SQL
            if (is_numeric($binding)) {
                $binding = $binding;
            } else {
                $binding = "'" . addslashes($binding) . "'";
            }
            // Thay thế dấu ? bằng giá trị thực tế
            $sql = preg_replace('/\?/', $binding, $sql, 1);
        }

        return $sql;
    }

    public function find($id)
    {
        DB::beginTransaction();
        try {
            $business_id = Auth::guard('api')->user()->business_id;
            return Brands::with('created_by', 'updated_by')
                ->where('business_id', $business_id)->find($id);
        } catch (\Exception $e) {
            DB::rollBack();
            $message = $e->getMessage();

            throw new HttpException($message, [], 500);
        }
    }

    public function create($data)
    {
        DB::beginTransaction();

        try {
            $business_id = Auth::guard('api')->user()->business_id;
            $user_id = Auth::guard('api')->user()->id;
            $data['business_id'] = $business_id;
            $data['created_by'] = $user_id;
            $data['created_at'] = now();

            if (isset($input['image'])) {
                $urlImage = !empty($data["image"]) ? str_replace(tera_url("/"), "", $data["image"]) : "";
                $data['image'] = $urlImage;
            }
            $brand = Brands::create($data);

            if ($brand) {
                $message = "[$brand->name]";
                Activity::activityLog($message, $brand->id, "crm_brand", "created", $user_id, [
                    "username" => Auth::guard('api')->user()->username
                ]);
            }
            if (!$brand) {
                DB::rollBack();
                $message = "Lỗi trong quá trình tạo nhãn hiệu";
                throw new DatabaseException($message, [], 503);
            }

            DB::commit();
            return $brand;
        } catch (\Exception $e) {
            DB::rollBack();
            $message = $e->getMessage();
            throw new HttpException($message, 500, []);
        }
    }

    public function createManyOfRow($data)
    {
        try {
            $result = $this->CreateManyRow($data);

            return $result;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function update($input) {}

    public function delete($id) {}
}
