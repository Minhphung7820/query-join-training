<?php

namespace App\Module\BakeTeach\Repository;

use App\Model\User;
use App\Module\BakeTeach\Helpers\Helper;
use App\Module\BakeTeach\Model\Affiliate;
use App\Module\BakeTeach\Model\AffiliateConfig;
use App\Module\BakeTeach\Model\Card;
use App\Module\BakeTeach\Model\CardType;
use App\Module\BakeTeach\Model\Course;
use App\Module\BakeTeach\Model\CourseCategory;
use App\Module\BakeTeach\Model\CourseInvoice;
use App\Module\BakeTeach\Model\Invitation;
use App\Module\BakeTeach\Model\Invoice;
use App\Module\BakeTeach\Model\RequestWithdrawal;
use App\Module\BakeTeach\Model\Transaction;
use App\Module\BakeTeach\Model\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Package\Exception\HttpException;

class AffiliateRepository
{
    public $helper;

    public function __construct()
    {
        $this->helper = new Helper();
    }

    public function all($request)
    {
        $query = User::with(['affiliate' => function ($query) {
            $query->withSum('commissions', 'commission');
        }])->whereHas('affiliate')
            ->when(isset($request['keyword']), function ($query) use ($request) {
                return $query->where(function ($query) use ($request) {
                    $query->where('full_name', 'LIKE', "%" . $request['keyword'] . "%");
                    $query->orWhere('code', 'LIKE', "%" . $request['keyword'] . "%");
                    $query->orWhereHas('affiliate', function ($query) use ($request) {
                        $query->where('code', 'LIKE', "%" . $request['keyword'] . "%");
                    });
                });
            })->when(isset($request['from_register_date']), function ($query) use ($request) {
                return $query->whereHas('affiliate', function ($query) use ($request) {
                    $query->whereDate('created_at', '>=', Carbon::parse($request['from_register_date']));
                });
            })->when(isset($request['to_register_date']), function ($query) use ($request) {
                return $query->whereHas('affiliate', function ($query) use ($request) {
                    $query->whereDate('created_at', '<=', Carbon::parse($request['to_register_date']));
                });
            })->when(isset($request['from_commission_per']), function ($query) use ($request) {
                return $query->whereHas('affiliate', function ($query) use ($request) {
                    $query->where('commission_per', '>=', $request['from_commission_per']);
                });
            })->when(isset($request['to_commission_per']), function ($query) use ($request) {
                return $query->whereHas('affiliate', function ($query) use ($request) {
                    $query->where('commission_per', '<=', $request['to_commission_per']);
                });
            })->orderBy(
                Affiliate::select('created_at')
                    ->whereColumn('affiliates.user_id', 'users.id'),
                'desc'
            );

        $data = $query->paginate($request['limit'] ?? 10);

        return $data;
    }

    public function find($id)
    {
        return CourseCategory::with([
            'user_created:id,full_name,avatar',
            'user_updated:id,full_name,avatar'
        ])->findOrFail($id);
    }

    public function create($data = [])
    {
        return Affiliate::create($data);
    }

    public function checkRegistered($id)
    {
        return Affiliate::where('user_id', $id)->exists();
    }

    public function getAffiliateByUser($id)
    {
        return Affiliate::where('user_id', $id)->first();
    }

    public function totalRequestWithdrawalPending($id, $course_id = null, $withdrawal_affiliate = 'withdrawal_affiliate')
    {
        return RequestWithdrawal::where('user_id', $id)
            ->where('type', 'withdrawal')
            ->where('type_withdrawal', $withdrawal_affiliate)
            ->when(!is_null($course_id), function ($query) use ($course_id) {
                return $query->where('course_id', $course_id);
            })->where('status', 'pending')->sum('money');
    }

    public function totalRequestWithdrawalApproved($id, $course_id = null, $withdrawal_affiliate = 'withdrawal_affiliate')
    {
        return RequestWithdrawal::where('user_id', $id)
            ->where('type', 'withdrawal')
            ->where('type_withdrawal', $withdrawal_affiliate)
            ->when(!is_null($course_id), function ($query) use ($course_id) {
                return $query->where('course_id', $course_id);
            })->where('status', 'approve')->sum('money');
    }

    public function update($data)
    {
        return CourseCategory::where('id', $data['id'])->update($data);
    }

    public function getUserByMail($email)
    {
        return User::where('email', $email)->first();
    }

    public function delete($id)
    {
        return CourseCategory::where('id', $id)->delete();
    }

    public function config($data)
    {
        $find = AffiliateConfig::first();
        if ($find) {
            $find->update($data);
        } else {
            $find = AffiliateConfig::create($data);
        }
        return $find;
    }

    public function getConfig()
    {
        return AffiliateConfig::first();
    }

    public function affiliateCount()
    {
        return Affiliate::whereHas('userCurrent')->count();
    }

    public function listOrder($request)
    {
        //
        $course_invoices = DB::table('course_invoices')->leftJoin('users', function ($join) {
            $join->on('users.id', '=', 'course_invoices.user_id');
        })->where('affiliate_id', $request['affiliate_id'])
            ->when(isset($request['keyword']), function ($query) use ($request) {
                return $query->where(function ($query) use ($request) {
                    $query->where('course_invoices.code', 'LIKE', "%" . $request['keyword'] . "%");
                    $query->orWhere('users.full_name', 'LIKE', "%" . $request['keyword'] . "%");
                });
            })
            ->when(isset($request['start_order_date']), function ($query) use ($request) {
                return $query->whereDate('course_invoices.created_at', '>=', Carbon::parse($request['start_order_date']));
            })
            ->when(isset($request['end_order_date']), function ($query) use ($request) {
                return $query->whereDate('course_invoices.created_at', '<=', Carbon::parse($request['end_order_date']));
            })
            ->when(isset($request['type_order']), function ($query) use ($request) {
                return $query->where('course_invoices.classification', explode("_", $request['type_order'])[0]);
            })
            ->when(isset($request['from_price']), function ($query) use ($request) {
                return $query->where('course_invoices.price', '>=', $request['from_price']);
            })
            ->when(isset($request['to_price']), function ($query) use ($request) {
                return $query->where('course_invoices.price', '<=', $request['to_price']);
            })
            ->when(isset($request['from_commission']), function ($query) use ($request) {
                return $query->where('course_invoices.commission', '>=', $request['from_commission']);
            })
            ->when(isset($request['to_commission']), function ($query) use ($request) {
                return $query->where('course_invoices.commission', '<=', $request['to_commission']);
            })
            ->when(isset($request['status']), function ($query) use ($request) {
                return $query->having('status', $request['status']);
            })
            ->select([
                "course_invoices.id",
                "course_invoices.code",
                "course_invoices.user_id",
                "course_invoices.price",
                "users.full_name as student_name",
                "course_invoices.created_at as order_date",
                "course_invoices.status_complete as status",
                "course_invoices.commission",
                DB::raw("CASE
        WHEN course_invoices.classification = 'offline' THEN 'offline_course'
        WHEN course_invoices.classification = 'online' THEN 'online_course'
        ELSE 'unknown'
     END as type")
            ])->orderBy('course_invoices.created_at', 'desc');
        //
        return $course_invoices
            ->paginate($request['limit'] ?? 10);
    }

    public function createRequestWithdrawal($data = [])
    {
        return RequestWithdrawal::create($data);
    }

    public function approveRequestWithdrawal($id)
    {
        return $this->changeStatus($id);
    }

    public function cancelRequestWithdrawal($id)
    {
        return $this->changeStatus($id, 'cancel');
    }

    private function changeStatus($id, $status = 'approve')
    {
        $request = RequestWithdrawal::with('user:id,full_name,phone')->findOrFail($id);
        if ($request->status !== 'pending') {
            throw new HttpException("Trạng thái không hợp lệ !");
        }
        if ($status === 'cancel') {
            if (auth()->guard('api')->user()->type === 'admin') {
                $status = 'canceled_by_system';
            } else {
                $status = 'canceled_by_user';
            }
        }

        if ($status === 'approve') {
            if ($request->type_withdrawal !== 'withdrawal_commission' && auth()->guard('api')->user()->type !== 'admin') {
                throw new HttpException("Bạn không có quyền thực hiện chức năng này !");
            }

            if (is_null($request->course_id)) {
                $affiliate = Affiliate::where('user_id', $request->user_id)->first();

                if (!$affiliate) {
                    throw new HttpException("Người dùng này chưa tạo tiếp thị liên kết !");
                }

                $affiliate->total -= $request->money;
                $affiliate->save();
            }

            $wallet = Wallet::where('user_id', $request->user_id)->first();

            if ($wallet) {
                $wallet->original_balance += $request->money;
                $wallet->available_balance += $request->money;
                $wallet->updated_at = now();
                $wallet->save();
            } else {
                $wallet = new Wallet();
                $wallet->user_id = $request->user_id;
                $wallet->original_balance = $request->money;
                $wallet->available_balance = $request->money;
                $wallet->created_at = now();
                $wallet->save();
            }
            // create invoice
            $this->createInvoice([
                'invoice_id' => $request->id,
                'invoice_type' => is_null($request->course_id) ? 'invoice_affiliate_commission' : 'invoice_course_commission',
                'class_morph_children' => RequestWithdrawal::class,
                'invoice_code' => 'HD' . time(),
                'status' => 'approved',
                'methods' => 'wallet',
                'transaction_type' => is_null($request->course_id) ? 'affiliate_commission' : 'course_commission',
                'user_id' => $request->user_id,
                'amount' => $request->money,
                'user_name' => $request->user->full_name ?? null,
                'user_phone' => $request->user->phone ?? null,
            ]);
            //
        }
        $request->status = $status;

        $request->save();

        return $request;
    }

    public function createInvoice($data = [])
    {
        return Invoice::create($data);
    }

    public function listRequestWithdrawal($request)
    {
        $array_status_where_in = ['pending', 'approve', 'canceled_by_user', 'canceled_by_system'];

        $query = RequestWithdrawal::with([
            'user:id,full_name,phone,email,avatar',
            'currentWallet'
        ])
            ->whereIn('status', $array_status_where_in)
            ->where('type_withdrawal', 'withdrawal_affiliate')
            ->when(
                auth()->guard('api')->user()->type !== 'admin',
                function ($query) {
                    $query->where('user_id', auth()->guard('api')->id());
                }
            );
        $query = $this->queryApplyFilterForWithdrawal($query, $request);

        return $query->orderBy('created_at', 'desc')->paginate($request['limit'] ?? 10);
    }

    private function queryApplyFilterForWithdrawal($object, $request, $flag = true)
    {
        return $object->when(isset($request['keyword']), function ($query) use ($request) {
            return $query->where(function ($query) use ($request) {
                $query->whereHas('user', function ($query) use ($request) {
                    $query->where('full_name', 'LIKE', "%" . $request['keyword'] . "%");
                    $query->orWhere('phone', 'LIKE', "%" . $request['keyword'] . "%");
                });
            });
        })->when(isset($request['status']) && $request['status'] && $flag, function ($query) use ($request) {
            return $query->when(auth()->guard('api')->user()->type !== 'admin', function ($query) use ($request) {
                return $query->when($request['status'] === 'canceled', function ($query) use ($request) {
                    $query->where(function ($query) use ($request) {
                        $query->where('status', 'canceled_by_user');
                        $query->orWhere('status', 'canceled_by_system');
                    });
                }, function ($query) use ($request) {
                    return $query->where('status', $request['status']);
                });
            }, function ($query) use ($request) {
                return $query->where('status', $request['status']);
            });
        })->when(isset($request['start_date']) && $request['start_date'], function ($query) use ($request) {
            return $query->whereDate('created_at', '>=', Carbon::parse($request['start_date']));
        })->when(isset($request['end_date']) && $request['end_date'], function ($query) use ($request) {
            return $query->whereDate('created_at', '<=', Carbon::parse($request['end_date']));
        })->when(isset($request['from_money']) && $request['from_money'], function ($query) use ($request) {
            return $query->where('money', '<=', $request['from_money']);
        })->when(isset($request['to_money']) && $request['to_money'], function ($query) use ($request) {
            return $query->where('money', '>=', $request['to_money']);
        })->when(isset($request['at_detail_affiliate']) && $request['at_detail_affiliate'], function ($query) use ($request) {
            return $query->where(function ($query) use ($request) {
                $query->where('status', 'approve')
                    ->whereHas('user.affiliate', function ($query) use ($request) {
                        $query->where('id', $request['at_detail_affiliate']);
                    });
            })->whereIn('type', ['withdrawal', 'receive']);
        }, function ($query) {
            return $query->where('type', 'withdrawal');
        })->when(isset($request['from_wallet_money']) && $request['from_wallet_money'], function ($query) use ($request) {
            return $query->whereHas('currentWallet', function ($query) use ($request) {
                $query->where('total', '>=', $request['from_wallet_money']);
            });
        })->when(isset($request['to_wallet_money']) && $request['to_wallet_money'], function ($query) use ($request) {
            return $query->whereHas('currentWallet', function ($query) use ($request) {
                $query->where('total', '<=', $request['to_wallet_money']);
            });
        });
    }

    public function detailRequestWithdrawal($id)
    {
        $data = RequestWithdrawal::with([
            'user:id,full_name,phone,email',
            'currentWallet'
        ])->findOrFail($id)->toArray();
        if (is_null($data['current_wallet'])) {
            $data['current_wallet']['total'] = 0;
        }
        return $data;
    }

    public function listSummaryRequestWithdrawal($request)
    {
        $array_status_where_in = ['pending', 'approve', 'canceled_by_user', 'canceled_by_system'];

        $summaryList = RequestWithdrawal::with([
            'user:id,full_name,phone,email',
            'currentWallet'
        ])
            ->whereIn('status', $array_status_where_in)
            ->where('type_withdrawal', 'withdrawal_affiliate')
            ->when(
                auth()->guard('api')->user()->type !== 'admin',
                function ($query) {
                    $query->where('user_id', auth()->guard('api')->id());
                }
            );
        $summaryList = $this->queryApplyFilterForWithdrawal($summaryList, $request, false)
            ->select([
                'status',
                DB::raw('COUNT(*) as total_count')
            ]);
        $summaryList = $summaryList->groupBy('status')->get();
        $totalCount = $summaryList->sum('total_count');
        $summaryList->push([
            'status' => 'all',
            'total_count' => $totalCount,
        ]);

        return $summaryList;
    }

    public function listInfoApp($request)
    {
        return Affiliate::where('user_id', auth()->guard('api')->id())
            ->withCount('referrals')
            ->withSum('commissionPending', 'commission')
            ->withSum('commissionApproved', 'money')
            ->withSum('commissions', 'commission')
            ->first();
    }

    public function getHistoryReferrals($request)
    {
        $affiliateId = Affiliate::where('user_id', auth()->guard('api')->id())->value('id');
        $query = DB::table('course_invoices')
            ->leftJoin('users', 'users.id', '=', 'course_invoices.user_id')
            ->whereIn('course_invoices.status_complete', ['unfinished', 'completed'])
            ->where('course_invoices.affiliate_id', $affiliateId);
        //
        $query = $this->queryApplyFilterHistoryReferrals($query, $request);
        //
        $query->select([
            'course_invoices.id',
            'course_invoices.status_complete as status',
            'users.email',
            'users.full_name',
            'course_invoices.created_at',
            'course_invoices.commission'
        ]);
        return $query->orderBy('course_invoices.created_at', 'desc')->paginate($request['limit'] ?? 10);
    }

    private function queryApplyFilterHistoryReferrals($object, $request, $flag = true)
    {
        return $object->when(isset($request['status']) && $flag && $request['status'], function ($query) use ($request) {
            return $query->where(function ($query) use ($request) {
                return $query->where('course_invoices.status_complete', $request['status']);
            });
        })->when(isset($request['keyword']), function ($query) use ($request) {
            return $query->where(function ($query) use ($request) {
                $query->where('users.full_name', 'LIKE', "%" . $request['keyword'] . "%");
            });
        });
    }

    public function summaryHistoryReferrals($request)
    {
        $affiliateId = Affiliate::where('user_id', auth()->guard('api')->id())->value('id');
        $summaryList = DB::table('course_invoices')
            ->leftJoin('users', 'users.id', '=', 'course_invoices.user_id')
            ->whereIn('course_invoices.status_complete', ['unfinished', 'completed'])
            ->where('course_invoices.affiliate_id', $affiliateId)
            ->select([
                'course_invoices.status_complete as status',
                DB::raw('COUNT(*) as total_count')
            ]);
        //
        $summaryList = $this->queryApplyFilterHistoryReferrals($summaryList, $request, false);
        //
        $summaryList = $summaryList->groupBy('status_complete')->get();
        $totalCount = $summaryList->sum('total_count');
        $summaryList->push([
            'status' => 'all',
            'total_count' => $totalCount,
        ]);

        return $summaryList;
    }

    public function detailStatisticRequestWithdrawal($request)
    {
        $affiliateId = $request['affiliate_id'];

        // queryInvoice
        $queryInvoice = CourseInvoice::where('affiliate_id', $affiliateId)
            ->where('status_complete', 'completed');

        // Tổng số người giới thiệu (referrer)
        $totalReferrer = (clone $queryInvoice)
            ->distinct('user_id')
            ->count();

        // Tổng commission
        $totalCommission = (clone $queryInvoice)
            ->sum('commission');

        // Tổng số tiền đã rút
        $totalWithdrew = RequestWithdrawal::where('type', 'withdrawal')
            ->where('type_withdrawal', 'withdrawal_affiliate')
            ->whereHas('user.affiliate', function ($query) use ($affiliateId) {
                $query->where('id', $affiliateId);
            })
            ->where('status', 'approve')
            ->sum('money');

        // Tính commission còn lại
        $totalCommissionRemaining = $totalCommission - max(0, round($totalWithdrew, 2));
        $totalCommissionRemaining = max(0, round($totalCommissionRemaining, 2));

        // Trả về kết quả
        return [
            'total_referrer' => $totalReferrer,
            'total_revenue' => 0,
            'total_commission' => $totalCommission,
            'total_commission_remaining' => $totalCommissionRemaining,
        ];
    }


    public function overview($request)
    {
        // Khởi tạo query chung
        $courseInvoicesQuery = CourseInvoice::whereNotNull('affiliate_id')
            ->whereNotNull('referral_code')
            ->whereHas('user');

        // Lấy số lượng referrals
        $numberOfReferrals = (clone $courseInvoicesQuery)
            ->distinct('user_id')
            ->count();

        // Tính tổng revenue và commission
        $totalRevenue = (clone $courseInvoicesQuery)->where('status_complete', 'completed')->sum('total');
        $totalAffiliateCommission = (clone $courseInvoicesQuery)->where('status_complete', 'completed')->sum('commission');

        // Tính tổng chi tiêu (expenditure)
        $totalExpenditure = DB::table('request_withdrawal')
            ->where('type', 'withdrawal')
            ->where('status', 'approve')
            ->where('type_withdrawal', 'withdrawal_affiliate')
            ->sum('money');
        // Trả về kết quả
        return [
            'number_of_referrals' => $numberOfReferrals,
            'total_revenue' => $totalRevenue,
            'total_affiliate_commission' => $totalAffiliateCommission,
            'total_expenditure' => $totalExpenditure,
        ];
    }

    public function userRatio($request)
    {
        // Query tổng số user
        $totalUser = DB::table('users')->count();

        // Số user tham gia affiliate (có trong bảng affiliates)
        $affiliateUsersCount = DB::table('affiliates')->distinct('user_id')->count('user_id');

        // Số user không tham gia affiliate
        $nonAffiliateUsersCount = $totalUser - $affiliateUsersCount;

        // Số user đã mua đơn (old_user)
        $oldUserCount = DB::table('course_invoices')
            ->leftJoin('affiliates', 'affiliates.id', '=', 'course_invoices.affiliate_id')
            ->whereNull(
                'affiliates.id'
            )->distinct('course_invoices.user_id')
            ->count('course_invoices.user_id');

        // Số user chưa mua đơn (new_user) trong nhóm không có affiliate
        $newUserCount = $nonAffiliateUsersCount - $oldUserCount;

        // Đảm bảo số liệu chính xác trong nhóm chưa tham gia affiliate
        $oldUserCount = min($oldUserCount, $nonAffiliateUsersCount);

        // Tính toán chính xác tỷ lệ
        $affiliatePercentage = $totalUser > 0 ? round(($affiliateUsersCount / $totalUser) * 100, 2) : 0;
        $nonAffiliatePercentage = 100 - $affiliatePercentage;

        $newUserPercentage = $nonAffiliateUsersCount > 0
            ? round(($newUserCount / $nonAffiliateUsersCount) * $nonAffiliatePercentage, 2)
            : 0;

        $oldUserPercentage = $nonAffiliateUsersCount > 0
            ? round(($oldUserCount / $nonAffiliateUsersCount) * $nonAffiliatePercentage, 2)
            : 0;

        // Trả về kết quả
        return [
            'total_user' => $totalUser,
            'affiliate_percentage' => $affiliatePercentage,
            'new_user_percentage' => $newUserPercentage,
            'old_user_percentage' => $oldUserPercentage,
        ];
    }

    public function marketingIndex($request)
    {
        $start_date = $request['start_date'] ?? null;
        $end_date = $request['end_date'] ?? null;
        // Parse start_date và end_date thành Carbon instances
        $start = $start_date ? Carbon::parse($start_date) : Carbon::now()->startOfWeek(Carbon::MONDAY);
        $end = $end_date ? Carbon::parse($end_date) : Carbon::now()->endOfWeek(Carbon::SUNDAY);

        // Tính tổng số click và affiliate
        $totalClick = Invitation::whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])->count();
        $totalAffiliate = Affiliate::whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])->count();

        // Query tổng hoa hồng và số lượng affiliates
        $affiliatesQuery = DB::table('course_invoices')
            ->where('status_complete', 'completed')
            ->whereNotNull('referral_code')
            ->whereNotNull('affiliate_id')
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()]);

        $totalCommissionAffiliates = $affiliatesQuery->sum('commission');
        $totalAffiliatesCount = $affiliatesQuery->count();

        // Tính tỷ lệ chuyển đổi, tránh chia cho 0
        $conversionRate = $totalAffiliatesCount > 0
            ? round((($totalAffiliatesCount / $totalAffiliate) * 100), 2)
            : 0;

        // Trả về kết quả dưới dạng mảng
        return [
            'total_click' => $totalClick,
            'total_affiliate' => $totalAffiliate,
            'conversion_rate' => $conversionRate,
            'total_commission_affiliates' => $totalCommissionAffiliates,
        ];
    }

    public function growthMarketing($request)
    {
        $start_date = $request['start_date'] ?? null;
        $end_date = $request['end_date'] ?? null;

        $start = $start_date ? Carbon::parse($start_date) : Carbon::now()->startOfWeek(Carbon::MONDAY);
        $end = $end_date ? Carbon::parse($end_date) : Carbon::now()->endOfWeek(Carbon::SUNDAY);

        $invoices = DB::table('course_invoices')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as total_count_invoice'),
                DB::raw('0 as total_count_affiliate'),
                DB::raw('0 as total_count_invitation')
            )
            ->whereNotNull('affiliate_id')
            ->whereNotNull('referral_code')
            ->where('status_complete', 'completed')
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->groupBy(DB::raw('DATE(created_at)'));

        $affiliates = DB::table('affiliates')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('0 as total_count_invoice'),
                DB::raw('COUNT(*) as total_count_affiliate'),
                DB::raw('0 as total_count_invitation')
            )
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->groupBy(DB::raw('DATE(created_at)'));

        $invitations = DB::table('invitations')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('0 as total_count_invoice'),
                DB::raw('0 as total_count_affiliate'),
                DB::raw('COUNT(*) as total_count_invitation')
            )
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->groupBy(DB::raw('DATE(created_at)'));

        // Sử dụng union để gộp kết quả từ các bảng
        $results = $invoices
            ->union($affiliates)
            ->union($invitations)
            ->orderBy('date', 'asc') // Sắp xếp theo ngày tăng dần
            ->get();

        // Gộp kết quả theo ngày
        $mergedResults = [];
        foreach ($results as $result) {
            $date = $result->date;

            if (!isset($mergedResults[$date])) {
                $mergedResults[$date] = [
                    'date' => $date,
                    'total_count_invoice' => 0,
                    'total_count_affiliate' => 0,
                    'total_count_invitation' => 0,
                ];
            }

            $mergedResults[$date]['total_count_invoice'] += $result->total_count_invoice;
            $mergedResults[$date]['total_count_affiliate'] += $result->total_count_affiliate;
            $mergedResults[$date]['total_count_invitation'] += $result->total_count_invitation;
        }

        // Chuyển về dạng array
        return array_values($mergedResults);
    }


    public function recentRevenue($request)
    {
        // Lấy các mốc thời gian cần thiết
        $today = Carbon::today(); // Ngày hôm nay
        $yesterday = Carbon::yesterday(); // Ngày hôm qua
        $thisWeekStart = Carbon::now()->startOfWeek(); // Đầu tuần
        $thisWeekEnd = Carbon::now()->endOfWeek(); // Cuối tuần
        $thisMonthStart = Carbon::now()->startOfMonth(); // Đầu tháng
        $thisMonthEnd = Carbon::now()->endOfMonth(); // Cuối tháng

        // Lấy đầu và cuối của quý hiện tại
        $thisQuarterStart = Carbon::now()->startOfQuarter(); // Đầu quý
        $thisQuarterEnd = Carbon::now()->endOfQuarter(); // Cuối quý

        // Query tính tổng với các điều kiện thời gian khác nhau bằng SQL thô (raw)
        $totals = DB::table('course_invoices')
            ->selectRaw("
        SUM(CASE
            WHEN DATE(created_at) = ? THEN total
            ELSE 0
        END) as total_today,
        SUM(CASE
            WHEN DATE(created_at) = ? THEN total
            ELSE 0
        END) as total_yesterday,
        SUM(CASE
            WHEN created_at BETWEEN ? AND ? THEN total
            ELSE 0
        END) as total_this_week,
        SUM(CASE
            WHEN created_at BETWEEN ? AND ? THEN total
            ELSE 0
        END) as total_this_month,
        SUM(CASE
            WHEN created_at BETWEEN ? AND ? THEN total
            ELSE 0
        END) as total_this_quarter
    ", [
                // Các tham số thời gian được truyền vào
                $today, $yesterday,
                $thisWeekStart, $thisWeekEnd,
                $thisMonthStart, $thisMonthEnd,
                $thisQuarterStart, $thisQuarterEnd
            ])
            ->whereNotNull('affiliate_id') // Lọc affiliate_id khác null
            ->whereNotNull('referral_code') // Lọc referral_code khác null
            ->where('status_complete', 'completed') // Lọc status_complete = 'completed'
            ->first(); // Lấy kết quả đầu tiên

        // Kết quả trả về
        return [
            'total_today' => $totals->total_today ?? 0,             // Tổng hôm nay
            'total_yesterday' => $totals->total_yesterday ?? 0,     // Tổng hôm qua
            'total_this_week' => $totals->total_this_week ?? 0,     // Tổng tuần này
            'total_this_month' => $totals->total_this_month ?? 0,   // Tổng tháng này
            'total_this_quarter' => $totals->total_this_quarter ?? 0 // Tổng quý này
        ];
    }

    public function topUser($request)
    {
        $topUsers = DB::table('course_invoices')
            ->join('users', 'course_invoices.user_id', '=', 'users.id')
            ->select('users.full_name', DB::raw('SUM(course_invoices.total) as total_amount'))
            ->whereNotNull('course_invoices.affiliate_id')
            ->whereNotNull('course_invoices.referral_code')
            ->where('course_invoices.status_complete', 'completed')
            ->groupBy('users.id', 'users.full_name')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get();

        return $topUsers;
    }
}
