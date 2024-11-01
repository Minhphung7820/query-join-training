<?php

namespace App\Module\Ecommerce\Helpers;

use App\Module\CRM\Model\Product;
use App\Module\CRM\Model\StockProduct;
use App\Module\Ecommerce\Model\Shopee\Auth\AccessToken;
use App\Module\Ecommerce\Model\Shopee\Order\Order;
use App\Module\Ecommerce\Model\Shopee\Order\OrderItem;
use App\Module\Ecommerce\Model\Shopee\Product\Link;
use App\Module\Ecommerce\Repository\Shopee\ProductRepository;
use App\Module\Finance\Model\Customer;
use CURLFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Package\Exception\HttpException;

class ShopeeHelper
{
    protected $partnerHost;

    protected $partnerId;

    protected $partnerKey;

    protected $repository;

    public const URL_API = [
        'list_item_base_info' => '/api/v2/product/get_item_base_info',
        'list_item' => '/api/v2/product/get_item_list',
        'update_price' => '/api/v2/product/update_price',
        'update_stock' => '/api/v2/product/update_stock',
        'list_order' => '/api/v2/order/get_order_list',
        'detail_order' => '/api/v2/order/get_order_detail',
        'refresh_access_token' => '/api/v2/auth/access_token/get'
    ];

    public function __construct()
    {
        $this->partnerHost = env('PARTNER_HOST');
        $this->partnerId = (int)env('PARTNER_ID');
        $this->partnerKey = env('PARTNER_KEY');
        //
        $this->repository = new ProductRepository();
    }

    public function getSign($partnerId, $apiPath, $timestamp, $accessToken, $shopId, $partnerKey)
    {
        // Tạo chuỗi để hash
        $baseString = sprintf(
            '%s%s%s%s%s',
            $partnerId,
            $apiPath,
            $timestamp,
            $accessToken,
            $shopId
        );
        // Tạo chữ ký bằng HMAC-SHA256
        $signature = hash_hmac('sha256', $baseString, $partnerKey);

        return $signature;
    }

    public function handleSyncOrderToTera($request, $userId, $businessId, $time, $stockShopee, $shopeeAuth)
    {
        $cloneItem = $this->handleClone($request, $userId, $businessId, $time, $stockShopee, $shopeeAuth, true, '');
        $syncToTera = $this->syncOrderToTera($request, $shopeeAuth, $businessId);
        if ($cloneItem && $syncToTera) {
            return true;
        } else {
            return false;
        }
    }

    public function syncOrderToTera($request, $getShopeeAuth, $businessId)
    {
        try {
            $ordersShopee = $this->getOrderDetail($request, $getShopeeAuth);
            // $ordersShopee = array_filter($this->getOrderDetail($request, $getShopeeAuth), function ($item) {
            //   return ($item['cod'] == true) || ($item['cod'] == false && !is_null($item['pay_time']));
            // });
            $shopId = $getShopeeAuth['shop_id'];
            //
            $buyerUpdates = [];
            $buyerInsert = [];
            //
            $buyers = json_decode(json_encode($this->repository->getBuyersMapping($businessId, $ordersShopee)), true);
            //
            foreach ($ordersShopee as $value) {
                $keyBuyer = "shopee-" . $value['buyer_user_id'];
                $dataBuyer = array(
                    'business_name' => $value['buyer_username'],
                    'type_eco' => 'shopee',
                    'buyer_eco_id' => $value['buyer_user_id'],
                    'business_id' => $businessId,
                    'type' => 'customer'
                );
                if (isset($buyers[$keyBuyer])) {
                    $dataBuyer['id'] = @$buyers[$keyBuyer]['id'];
                    $buyerUpdates[] = $dataBuyer;
                } else {
                    $dataBuyer['code'] = 'KHSHOPEE' . $value['buyer_user_id'];
                    $buyerInsert[] = $dataBuyer;
                }
            }

            $buyerInsert = array_values(array_reduce($buyerInsert, function ($carry, $item) {
                $carry[$item['buyer_eco_id']] = $item;
                return $carry;
            }, []));

            $buyerUpdates = array_values(array_reduce($buyerUpdates, function ($carry, $item) {
                $carry[$item['buyer_eco_id']] = $item;
                return $carry;
            }, []));

            if (!empty($buyerUpdates)) {
                $instanceBuyer = new Customer();
                batch()->update($instanceBuyer, $buyerUpdates, 'id');
            }

            if (!empty($buyerInsert)) {
                DB::table('crm_customers')->insert($buyerInsert);
            }
            //
            $buyersFresh = json_decode(json_encode($this->repository->getBuyersMapping($businessId, $ordersShopee)), true);
            //
            $insertsOrder = [];
            $updatesOrder = [];
            $itemsOrder = [];
            //
            $getOrder = $this->repository->getOrderMapping($shopId, $ordersShopee);
            //
            $itemIds = collect($ordersShopee)
                ->pluck('item_list.*.item_id')
                ->flatten()
                ->unique()
                ->values()
                ->toArray();
            //
            $productMappingItem = json_decode(json_encode($this->repository->getStockProductsMapping($itemIds)), true);
            //
            foreach ($ordersShopee as $value) {
                $keyBuyer = "shopee-" . $value['buyer_user_id'];

                //
                $data = array(
                    'code' => $value['order_sn'],
                    'create_time' => date('Y-m-d H:i:s', (int)$value['create_time']),
                    'address' => $value['recipient_address']['full_address'],
                    'total_amount' => $value['total_amount'],
                    'phone' => $value['recipient_address']['phone'],
                    'shop_id' => $shopId,
                    'contact_id' => @$buyersFresh[$keyBuyer]['id'],
                    'shipping_fee' => $value['estimated_shipping_fee'],
                    'created_at' => now()->format('Y-m-d H:i:s')
                );
                //
                $this->setStatus($data, $value);
                //
                foreach ($value['item_list'] as $item) {
                    $itemsOrder[] = array(
                        'order_code' => $value['order_sn'],
                        'item_eco_id' => $item['item_id'],
                        'product_id' => @$productMappingItem[$item['item_id']]['product_id'],
                        'stock_id' => @$productMappingItem[$item['item_id']]['stock_id'],
                        'price' => $item['model_original_price'],
                        'quantity' => $item['model_quantity_purchased'],
                        'created_at' => now()->format('Y-m-d H:i:s')
                    );
                }
                //
                if (isset($getOrder[$value['order_sn']])) {
                    $updatesOrder[] = $data;
                } else {
                    $insertsOrder[] = $data;
                }
            }

            if (!empty($updatesOrder)) {
                $instanceOrder = new Order();
                batch()->update($instanceOrder, $updatesOrder, 'code');
            }

            if (!empty($insertsOrder)) {
                Order::insert($insertsOrder);
            }
            //
            $updatesItemsOrder = [];
            $InsertsItemsOrder = [];
            $getItemOrder = $this->repository->getOrderItemMapping($itemsOrder);

            foreach ($itemsOrder as $itemOrder) {
                $key = $itemOrder['order_code'] . "-" . $itemOrder['item_eco_id'];
                if (isset($getItemOrder[$key])) {
                    $updatesItemsOrder[] = array_merge($itemOrder, ['id' => $getItemOrder[$key]['id']]);
                } else {
                    $InsertsItemsOrder[] = $itemOrder;
                }
            }

            if (!empty($updatesItemsOrder)) {
                $instanceItemOrder = new OrderItem();
                batch()->update($instanceItemOrder, $updatesItemsOrder, 'id');
            }

            if (!empty($InsertsItemsOrder)) {
                OrderItem::insert($InsertsItemsOrder);
            }
            return true;
        } catch (HttpException $e) {
            throw new HttpException($e->getMessage(), 500, []);
        }
    }

    protected function setStatus(&$value, $data)
    {
        $statusCOD = '';
        $status = '';
        $statusPayment = '';
        $statusShipping = '';
        // status
        $status = strtolower($data['order_status']);
        // status COD
        if ($data['order_status'] == 'UNPAID' && $data['cod'] == true) {
            $statusCOD = 'not_yet_received';
        }

        if ($data['order_status'] == 'PAID' && $data['cod'] == true && !is_null($data['pay_time'])) {
            $statusCOD = 'received';
        }

        if ($data['cod'] == false) {
            $statusCOD = 'no_collection';
        }
        // status shipping
        $statusShipping = strtolower($data['package_list'][0]['logistics_status']);
        // status payment
        if (!is_null($data['pay_time'])) {
            $statusPayment = 'paid';
        } else {
            $statusPayment = 'unpaid';
        }
        //
        $value['status'] = $status;
        $value['shipping_status'] = $statusShipping;
        $value['payment_status'] = $statusPayment;
        $value['cod_status'] = $statusCOD;
    }

    public function listOrder($request, $getShopeeAuth)
    {
        $shopId = (int)$getShopeeAuth['shop_id'];
        $accessToken = (string)$getShopeeAuth['access_token'];
        $timest = time();
        $path = self::URL_API['list_order'];
        $apiUrl = $this->partnerHost . $path;
        $allOrders = [];
        $pageSize = 100;
        $cursor = null;

        do {
            $arrayParamsCommon = [
                'partner_id' => (int)$this->partnerId,
                'timestamp' => $timest,
                'access_token' => $accessToken,
                'shop_id' => $shopId,
                'sign' => $this->getSign($this->partnerId, $path, $timest, $accessToken, $shopId, $this->partnerKey),
                'time_range_field' => 'create_time', // Trường bắt buộc
                'time_from' => (int)now()->subDays(15)->timestamp, // Lấy từ thời điểm xa nhất có thể
                'time_to' => (int)now()->timestamp, // Đến thời điểm hiện tại
                'page_size' => $pageSize,
            ];

            if ($cursor) {
                $arrayParamsCommon['cursor'] = $cursor;
            }

            $arrayParamsCommon = Arr::query($arrayParamsCommon);
            $response = Http::get($apiUrl . '?' . $arrayParamsCommon);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['response'])) {
                    $items = $data['response']['order_list'];
                    $allOrders = array_merge($allOrders, $items);

                    if (isset($data['response']['next_cursor'])) {
                        $cursor = $data['response']['next_cursor'];
                    } else {
                        $cursor = null;
                    }
                }
            } else {
                throw new HttpException($response->body(), 500, []);
            }
        } while ($cursor);

        return $allOrders;
    }

    public function getOrderDetail($request, $getShopeeAuth)
    {
        $listItem = $this->listOrder($request, $getShopeeAuth);
        $orderList = array_column($listItem, 'order_sn');
        $shopId = (int)$getShopeeAuth['shop_id'];
        $accessToken = (string)$getShopeeAuth['access_token'];
        $timest = time();
        $path = self::URL_API['detail_order'];
        $apiUrl = $this->partnerHost . $path;
        $allOrders = [];
        $cursor = null;

        do {
            $responseOptionalFields = implode(',', [
                'buyer_user_id',
                'buyer_username',
                'estimated_shipping_fee',
                'recipient_address',
                'actual_shipping_fee',
                'goods_to_declare',
                'note',
                'note_update_time',
                'item_list',
                'pay_time',
                'dropshipper',
                'dropshipper_phone',
                'split_up',
                'buyer_cancel_reason',
                'cancel_by',
                'cancel_reason',
                'actual_shipping_fee_confirmed',
                'buyer_cpf_id',
                'fulfillment_flag',
                'pickup_done_time',
                'package_list',
                'shipping_carrier',
                'payment_method',
                'total_amount',
                'invoice_data',
                'no_plastic_packing',
                'order_chargeable_weight_gram',
                // Thêm các field khác nếu có
            ]);
            $arrayParamsCommon = [
                'partner_id' => (int)$this->partnerId,
                'timestamp' => $timest,
                'access_token' => $accessToken,
                'shop_id' => $shopId,
                'sign' => $this->getSign($this->partnerId, $path, $timest, $accessToken, $shopId, $this->partnerKey),
                'order_sn_list' => (string)implode(",", $orderList), // Trường bắt buộc
                'response_optional_fields' => $responseOptionalFields
            ];

            if ($cursor) {
                $arrayParamsCommon['cursor'] = $cursor;
            }

            $arrayParamsCommon = Arr::query($arrayParamsCommon);
            $response = Http::get($apiUrl . '?' . $arrayParamsCommon);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['response'])) {
                    $items = $data['response']['order_list'];
                    $allOrders = array_merge($allOrders, $items);

                    if (isset($data['response']['next_cursor'])) {
                        $cursor = $data['response']['next_cursor'];
                    } else {
                        $cursor = null;
                    }
                }
            } else {
                throw new HttpException($response->body(), 500, []);
            }
        } while ($cursor);
        return $allOrders;
    }

    public function handleClone($request, $userId, $businessId, $time, $stockShopee, $shopeeAuth, $cronJob = false, $bearerToken = '')
    {
        if (isset($request['data_type']) && $request['data_type']) {
            //
            $products = [];
            //
            switch ($request['data_type']) {
                case 'active':
                    $request = array_merge($request, ['status_string' => 'item_status=NORMAL']);
                    break;
                case 'all':
                    $statusString = 'item_status=NORMAL';
                    $statusString .= '&item_status=BANNED';
                    $statusString .= '&item_status=UNLIST';
                    $request = array_merge($request, ['status_string' => $statusString]);
                    break;
            }
            $listItem = $this->listItem($request, $shopeeAuth);
            $itemIds = array_map(function ($item) {
                return (int)$item;
            }, array_column($listItem, 'item_id'));
            $request = array_merge($request, ['item_ids' => $itemIds]);
            if (!empty($listItem)) {
                $listItemInfo = $this->listItemInfo($request, $shopeeAuth);
                $products = $listItemInfo;
            }
            //
            $products = array_filter($products, function ($itemFilter) {
                return $itemFilter['has_model'] == false;
            });
            //
            if (!empty($products)) {
                $this->syncItemsToTera($userId, $businessId, $stockShopee, $time, $products, $shopeeAuth, $cronJob, $bearerToken);
            }
            return true;
        }
        return false;
    }

    public function handleSyncItemToShopee($request, $userId, $businessId, $time, $stockShopee, $shopeeAuth)
    {
        $needSyncPrice = $shopeeAuth['config']['sync_price'] ?? 0;
        $needSyncQuantity = $shopeeAuth['config']['sync_quantity'] ?? 0;
        $statusString = 'item_status=NORMAL';
        $statusString .= '&item_status=BANNED';
        $statusString .= '&item_status=UNLIST';
        $request = array_merge($request, ['status_string' => $statusString]);
        $products = $this->getItemsShopee($request, $shopeeAuth);
        //
        $barcode = $this->repository->getBarcodeProduct($request['product_id']);
        if (!$barcode || !in_array($barcode, array_column($products, 'item_sku'))) {
            $message = '';
            if (!$barcode) {
                $message = 'Mã barcode đang rỗng !';
            }

            if (!in_array($barcode, array_column($products, 'item_sku'))) {
                $message = 'Không tìm thấy sản phẩm trên shopee !';
            }
            $this->repository->logSyncFail($request['product_id'], $message);
            throw new HttpException("Đồng bộ thất bại !");
        }
        //
        $infoToSync = $this->repository->getInfoToSync($businessId, $stockShopee->id, $request['product_id']);
        $price = $infoToSync->unit_price ?? null;
        $quantity = $infoToSync->quantity ?? null;
        $itemId = null;
        $updateTime = null;
        foreach ($products as $key => $value) {
            if ($value['item_sku'] == $barcode) {
                $itemId = $value['item_id'];
                $updateTime = $value['update_time'];
                break;
            }
        }

        if ($needSyncPrice == 1 && !is_null($price)) {
            $request['price'] = $price;
            $request['item_id'] = $itemId;
            $this->syncPrice($request, $shopeeAuth);
        }

        if ($needSyncQuantity == 1 && !is_null($quantity)) {
            $request['quantity'] = $quantity;
            $request['item_id'] = $itemId;
            $this->syncStock($request, $shopeeAuth);
        }

        $this->repository->logSyncSuccess($request['product_id'], [
            'item_code_id' => $itemId,
            'shop_id' => @$shopeeAuth['shop_id'],
            'update_time' => date('Y-m-d H:i:s', (int)$updateTime)
        ]);
        return true;
    }

    public function getItemsShopee($request, $shopeeAuth)
    {
        $products = [];
        $listItem = $this->listItem($request, $shopeeAuth);
        $itemIds = array_map(function ($item) {
            return (int)$item;
        }, array_column($listItem, 'item_id'));
        $request = array_merge($request, ['item_ids' => $itemIds]);
        if (!empty($listItem)) {
            $listItemInfo = $this->listItemInfo($request, $shopeeAuth);
            $products = $listItemInfo;
        }
        return $products;
    }

    public function listItemInfo($request, $getShopeeAuth)
    {
        $shopId = (int)$getShopeeAuth['shop_id'];
        $accessToken = (string)$getShopeeAuth['access_token'];
        $timest = time();
        $path = self::URL_API['list_item_base_info'];
        $apiUrl = $this->partnerHost . $path;
        $sign = $this->getSign($this->partnerId, $path, $timest, $accessToken, $shopId, $this->partnerKey);
        //

        $arrayParamsCommon = [
            'partner_id' => (int)$this->partnerId,
            'timestamp' => $timest,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $sign
        ];

        $arrayParamsRequest = [
            'item_id_list' => implode(',', $request['item_ids'])
        ];
        $allParams = array_merge($arrayParamsCommon, $arrayParamsRequest);

        $response = Http::get($apiUrl, $allParams);
        // Kiểm tra và xử lý phản hồi
        if ($response->successful()) {
            $data = $response->json();
            if ($data['message'] === '') {
                return $data['response']['item_list'];
            } else {
                throw new HttpException($data['message']);
            }
        } else {
            throw new HttpException($response->body(), 500, []);
        }
    }

    public function listItem($request, $getShopeeAuth)
    {
        $shopId = (int)$getShopeeAuth['shop_id'];
        $accessToken = (string)$getShopeeAuth['access_token'];
        $timest = time();
        $path = self::URL_API['list_item'];
        $apiUrl = $this->partnerHost . $path;
        $sign = $this->getSign($this->partnerId, $path, $timest, $accessToken, $shopId, $this->partnerKey);

        $allProducts = [];
        $pageSize = 100;
        $offset = 0;
        $items = [];

        do {
            $arrayParamsCommon = [
                'partner_id' => (int)$this->partnerId,
                'timestamp' => $timest,
                'access_token' => $accessToken,
                'shop_id' => $shopId,
                'sign' => $sign,
                'offset' => $offset,
                'page_size' => $pageSize
            ];

            $arrayParamsCommon = Arr::query($arrayParamsCommon);
            if (isset($request['status_string']) && $request['status_string']) {
                $statusString = $request['status_string'];
                $arrayParamsCommon .= "&$statusString";
            }

            $response = Http::get($apiUrl . '?' . $arrayParamsCommon);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['response'])) {
                    if (isset($data['response']['item'])) {
                        $items = $data['response']['item'];
                        $allProducts = array_merge($allProducts, $items);
                        $offset += $pageSize;
                    }
                }
            } else {
                throw new HttpException($response->body(), 500, []);
            }
        } while (count($items) == $pageSize);

        return $allProducts;
    }

    protected function syncItemsToTera($userId, $businessId, $stockShopee, $time, $products = [], $shopeeAuth, $cronJob = false, $bearerToken = '')
    {
        try {
            $supplierShopeeId = $this->repository->getSupplierShopeeDefault($businessId, $shopeeAuth['shop_id'])->id;
            $existingRecords = json_decode(
                json_encode(
                    $this->repository->getByItemId($businessId, $products)
                ),
                true
            );
            $insertsProduct = [];
            $updatesProduct = [];

            foreach ($products as $value) {
                $key = 'shopee-' . $value['item_id'];
                $data = [
                    'contact_id' => $supplierShopeeId,
                    'barcode' => $value['item_sku'],
                    'name' => $value['item_name'],
                    'business_id' => $businessId,
                    'created_by' => $userId,
                    'created_at' => $time,
                    'type' => 'single',
                    'have_variant' => 0,
                    'sku' => 'SP' . substr(md5(rand()), 1, 5),
                    'key_mapping_eco' => 'shopee-' . $value['item_id']
                ];
                if (
                    (
                        isset($existingRecords[$key])
                        && $existingRecords[$key]['barcode'] !== ""
                        && (string)$existingRecords[$key]['barcode'] === (string)$value['item_sku']
                    )
                    ||
                    ((string)$value['item_sku'] !== ""
                        && in_array((string)$value['item_sku'], array_column(array_values($existingRecords), 'barcode')))
                ) {

                    $updatesProduct[] = array_merge($data, [
                        'id' => @$existingRecords[$key]['id']
                    ]);
                } else {
                    if (
                        $value['item_sku'] === ""
                        && in_array($key, array_column(array_values($existingRecords), 'key_mapping_eco'))
                    ) {
                        continue;
                    }
                    // if (!$cronJob) {
                    $getImage = $this->getUrlImagePortal(@$value['image']['image_url_list'][0], $bearerToken);
                    $data['image'] = str_replace(env('PORTAL_URL') . "/", "", @$getImage['data']['image']);
                    // }
                    $insertsProduct[] = $data;
                }
            }

            if (!empty($updatesProduct)) {
                $filteredUpdateProducts = collect($updatesProduct)->map(function ($item) use ($supplierShopeeId) {
                    $itemConvert = [
                        'id' => $item['id'],
                        'key_mapping_eco' => $item['key_mapping_eco'],
                        'contact_id' => $supplierShopeeId
                    ];
                    return $itemConvert;
                })->toArray();
                $instanceProducts = new Product();
                batch()->update($instanceProducts, $filteredUpdateProducts, 'id');
            }

            if (!empty($insertsProduct)) {
                DB::table('products')->insert($insertsProduct);
            }
            //
            $existingRecordsRefresh = json_decode(
                json_encode(
                    $this->repository->getByItemId($businessId, $products)
                ),
                true
            );
            //
            $updatesLink = [];
            $insertLink = [];
            $getLinks = json_decode(
                json_encode(
                    $this->repository->getLinks($businessId)
                ),
                true
            );
            foreach ($products as $value) {
                $key = 'shopee-' . $value['item_id'];
                $productId = $this->findKeyMapping($existingRecordsRefresh, $key);
                $barcode = $this->findKeyMapping($existingRecordsRefresh, $key, 'barcode');

                $dataLink = [
                    'product_id' => $productId,
                    'item_eco_id' => $value['item_id'],
                    'created_at' => now()->format('Y-m-d H:i:s'),
                    'shop_id' => @$shopeeAuth['shop_id'],
                    'update_time' => date('Y-m-d H:i:s', (int)@$value['update_time'])
                ];
                if (
                    isset($getLinks[$key])
                    && $barcode !== ""
                    && (string)$barcode === (string)$value['item_sku']
                ) {
                    $link = @$getLinks[$key];
                    $dataLink['status_link'] = 1;
                    $dataLink['status_sync'] = 1;
                    $updatesLink[] = array_merge($dataLink, ['id' => $link['id']]);
                } else {
                    if (
                        ((string)$value['item_sku'] !== ""
                            && in_array((string)$value['item_sku'], array_column(array_values($existingRecordsRefresh), 'barcode')))
                        && is_null($dataLink['product_id'])
                        ||
                        (
                            (string)$value['item_sku'] === ""
                            && in_array($key, array_column(array_values($existingRecordsRefresh), 'key_mapping_eco'))
                            && in_array($key, array_keys($getLinks))
                        )
                    ) {
                        continue;
                    }
                    $dataLink['status_link'] = $value['item_sku'] === "" ? 0 : 1;
                    $dataLink['status_sync'] = $value['item_sku'] === "" ? 0 : 1;
                    $insertLink[] = $dataLink;
                }
            }

            if (!empty($updatesLink)) {
                $instanceLink = new Link();
                batch()->update($instanceLink, $updatesLink, 'id');
            }

            if (!empty($insertLink)) {
                DB::table('crm_eco_link_products')->insert($insertLink);
            }
            //
            $insertsStockProduct = [];
            $updatesStockProduct = [];

            $existingStockProductRecords = json_decode(
                json_encode(
                    $this->repository->getStockProductByItemId($businessId, $products, $stockShopee->id)
                ),
                true
            );

            foreach ($products as $value) {
                $key = 'shopee-' . $value['item_id'];
                $productId = $this->findKeyMapping($existingRecordsRefresh, $key);
                $barcode = $this->findKeyMapping($existingRecordsRefresh, $key, 'barcode');

                $dataStockProduct = [
                    'is_main_stock' => 1,
                    'item_eco_id' => $value['item_id'],
                    'type_eco' => 'shopee',
                    'stock_id' => $stockShopee->id,
                    'status_eco' => strtolower($value['item_status']),
                    'status' => 'approve',
                    'combo_unit_price' => 0
                ];
                //
                $product = @$existingRecordsRefresh[$key];
                //
                if (!$product) {
                    continue;
                }
                if (
                    isset($existingStockProductRecords[$key])
                    && $existingStockProductRecords[$key] !== ""
                    && (int)$existingStockProductRecords[$key]['product_id'] === (int)$product['id']
                    && (string)$existingStockProductRecords[$key]['barcode'] === (string)$product['barcode']
                ) {
                    if ($barcode !== $value['item_sku']) {
                        continue;
                    }
                    //
                    $stockProduct = @$existingStockProductRecords[$key];
                    //
                    $dataMerge = array_merge([
                        'id' => @$stockProduct['id'],
                        'product_id' => @$product['id'],
                    ], $dataStockProduct);
                    $dataMerge['stock_id'] = $stockShopee->id;
                    //
                    $this->checkInfoSyncItemsToTera($dataMerge, $value, $shopeeAuth);
                    //
                    $updatesStockProduct[] = $dataMerge;
                } else {
                    //
                    $dataMerge = array_merge([
                        'product_id' => @$product['id']
                    ], $dataStockProduct);
                    $dataMerge['stock_id'] = $stockShopee->id;
                    //
                    $this->checkInfoSyncItemsToTera($dataMerge, $value, $shopeeAuth);
                    //
                    $insertsStockProduct[] = $dataMerge;
                }
            }

            if (!empty($updatesStockProduct)) {
                $instanceStockProducts = new StockProduct();
                batch()->update($instanceStockProducts, $updatesStockProduct, 'id');
            }
            if (!empty($insertsStockProduct)) {
                DB::table('stock_products')->insert($insertsStockProduct);
            }

            $this->repository->cancelLinkProductNoBarcode($businessId, $stockShopee->id, array_column($insertsStockProduct, 'item_eco_id'), array_column($insertsStockProduct, 'product_id'));

            return true;
        } catch (HttpException $e) {
            throw new HttpException($e->getMessage(), 500, []);
        }
    }

    public function findKeyMapping($arrays, $keyFind, $filed = 'id')
    {
        $data = null;
        foreach ($arrays as $key => $value) {
            if ($value['key_mapping_eco'] == $keyFind) {
                $data = $value[$filed];
            }
        }
        return $data;
    }

    public function getUrlImagePortal($url, $bearerToken = '')
    {
        $imageData = file_get_contents($url);

        $imageBytes = $imageData;

        $filePath = storage_path('app/public/image.jpg');
        file_put_contents($filePath, $imageBytes);
        $token = $bearerToken;
        // $token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiIxIiwianRpIjoiN2I1ZWI3NjNlODVkOTFmMGNlYmZmMzZmYWU1YjM2ZTUwMTVmYjhmYjJlZjhmZjI1NmY1ZTFkOGI4ODQyYTFjOTA2MjRiZGU3ODNhNmE2ODAiLCJpYXQiOjE3MjI0MjAyOTAuMTU2ODE5LCJuYmYiOjE3MjI0MjAyOTAuMTU2ODIzLCJleHAiOjE3MzgzMTc4OTAuMTU1NDQyLCJzdWIiOiIxMDI0NCIsInNjb3BlcyI6W119.CstPXJOn1RcD2EJAdU0byYRlcehXXaUpF2HLwKDtvSZ_Z5ylRpXALJTUBeP7JYp4tkWT9LZ2KIJYjHaYNSFvzDlCzPVezJK-sNKFMJfD0VAj40B3YTXqljuLEAd4cRvDzwBmdAhl48_Zj5ekGHZgni1oNhl5ZWBWc2D84JQ3Kz_4j8_Z5mZxGgeDPMGB5VMnMXGl60nKLryLhq2yeg1h2_fGuD8Us33kK81Xzzgb8TDNtbu0SlFZz7COalT0yW2sw7KNjvzlJ_xTMTDw__5nCgxlQAdtNwbw8I1-Sk3raOBdhUUYJEzXE_IEDEQTp9kydZkbs2fwLgfQ1vvwaGnVNfJTNsjh20YXlbhJhcPzJWJgAW-NGl1JknkEcdsP0kK1xnyT2sPJyrl1K4Aw7Qjam8DtfBe9mG7AAj7O8ltShsfw8ThLPuWOHQOBTAPDYzvmWuCmSyffeiDqP-dqN4EehfOQw68I1rxhCBz0ht01Aq6LS6PmWNb79H6XwtlTlcG-SWXA5ap_8m8a2O8bDwKdDF-QICXhWwBu-672RItswCdS6P2P7Ot7UjegqJois5P9qnW9sZKGUUy11nO7yS0qc-iLQeSuVBmasfZ3AiW63_TSbwzO-6dUlz_bURp17EkvBeA_tLDIQJMq5G5lVaJiatea0W3NSvkiPFqn2KQ8I0c';
        //
        $curl = curl_init();
        Log::info("Log thu : " . $token);
        curl_setopt_array($curl, [
            CURLOPT_URL => env('PORTAL_URL') . '/api/file/upload',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($filePath, 'image/jpeg', time() . uniqid() . 'temp_image.jpg'),
                'app_id' => '2',
                'object_id' => 'thumbnail',
                'object_key' => 'product',
                'folder' => 'product',
                'secure_code' => 'tera',
            ],
            CURLOPT_HTTPHEADER => [
                'authorization: Bearer ' . $token,
                'device-code: tera',
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        return json_decode($response, true);
    }

    protected function checkInfoSyncItemsToTera(&$data, $dataShope, $shopeeAuth)
    {
        $needSyncPrice = $shopeeAuth['config']['sync_price'] ?? 0;
        $needSyncQuantity = $shopeeAuth['config']['sync_quantity'] ?? 0;
        if ($needSyncPrice == 1) {
            $data['unit_price'] = $dataShope['price_info'][0]['original_price'];
            $data['purchase_price'] = $dataShope['price_info'][0]['original_price'];
        }
        if ($needSyncQuantity == 1) {
            $data['quantity'] = $dataShope['stock_info_v2']['seller_stock'][0]['stock'];
        }
    }

    public function syncPrice($request, $getShopeeAuth)
    {
        $shopId = (int)$getShopeeAuth['shop_id'];
        $accessToken = (string)$getShopeeAuth['access_token'];
        $timest = time();
        $path = self::URL_API['update_price'];
        $apiUrl = $this->partnerHost . $path;
        $sign = $this->getSign($this->partnerId, $path, $timest, $accessToken, $shopId, $this->partnerKey);
        //

        $arrayParamsCommon = [
            'partner_id' => (int)$this->partnerId,
            'timestamp' => $timest,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $sign
        ];

        $arrayParamsRequest = [
            'item_id' => (int)$request['item_id'],
            'price_list' => [
                [
                    'original_price' => (float)$request['price'],
                ],
            ],
        ];

        $paramsUrl = Arr::query($arrayParamsCommon);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($apiUrl . '?' . $paramsUrl, $arrayParamsRequest);

        if ($response->successful()) {
            $data = $response->json();
            if ($data['message'] === '') {
                return $data['response'];
            } else {
                throw new HttpException($data['message']);
            }
        } else {
            throw new HttpException($response->body(), 500, []);
        }
    }

    public function syncStock($request, $getShopeeAuth)
    {
        $shopId = (int)$getShopeeAuth['shop_id'];
        $accessToken = (string)$getShopeeAuth['access_token'];
        $timest = time();
        $path = self::URL_API['update_stock'];
        $apiUrl = $this->partnerHost . $path;
        $sign = $this->getSign($this->partnerId, $path, $timest, $accessToken, $shopId, $this->partnerKey);
        //

        $arrayParamsCommon = [
            'partner_id' => (int)$this->partnerId,
            'timestamp' => $timest,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $sign
        ];

        $arrayParamsRequest = [
            'item_id' => (int)$request['item_id'],
            'stock_list' => [
                [
                    'seller_stock' => [
                        [
                            'stock' => (int)$request['quantity']
                        ]
                    ]
                ]
            ]
        ];

        $paramsUrl = Arr::query($arrayParamsCommon);
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($apiUrl . '?' . $paramsUrl, $arrayParamsRequest);

        if ($response->successful()) {
            $data = $response->json();
            if ($data['message'] === '') {
                return $data['response'];
            } else {
                throw new HttpException($data['message']);
            }
        } else {
            throw new HttpException($response->body(), 500, []);
        }
    }

    private function getSignForAuth($partnerId, $partnerKey, $path, $timest)
    {
        $baseString = sprintf("%s%s%s", $partnerId, $path, $timest);
        return hash_hmac('sha256', $baseString, $partnerKey);
    }

    public function refreshAccessToken($businessId, $userId, $getShopeeAuth)
    {
        $shopId = (int)$getShopeeAuth['shop_id'];
        $timest = (int)time();
        $path = self::URL_API['refresh_access_token'];
        $partnerId = (int)$this->partnerId;
        $partnerKey = $this->partnerKey;

        $sign = $this->getSignForAuth($partnerId, $partnerKey, $path, $timest);

        $refreshToken = AccessToken::where('shop_id', $shopId)->where('user_id', $userId)->value('refresh_token');

        $params = [
            'partner_id' => (int)$this->partnerId,
            'shop_id' => (int)$shopId,
            'refresh_token' => $refreshToken,
        ];

        $response = Http::post($this->partnerHost . $path . "?partner_id=$partnerId&timestamp=$timest&sign=$sign", $params);

        if ($response->successful()) {
            $data = $response->json();
            $newAccessToken = $data['access_token'];
            $newRefreshToken = $data['refresh_token'];
            $arrayAddAccessToken = array(
                "refresh_token" => $newRefreshToken,
                "access_token" => $newAccessToken,
                "expire_in" => $data["expire_in"],
                "request_id" => $data["request_id"],
                "shop_id" => $shopId,
                "user_id" => $userId,
                "code" => null,
                "created_at" => now()->format('Y-m-d H:i:s')
            );

            AccessToken::updateOrCreate([
                "shop_id" => $shopId ?? null
            ], $arrayAddAccessToken);

            return true;
        } else {
            throw new \Exception('Failed to refresh access token: ' . $response->body());
        }
    }
}
