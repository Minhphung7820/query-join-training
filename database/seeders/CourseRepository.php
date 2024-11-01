<?php

namespace App\Module\BakeTeach\Repository;

use App\Module\BakeTeach\Helpers\Helper;
use App\Module\BakeTeach\Model\Card;
use App\Module\BakeTeach\Model\CardType;
use App\Module\BakeTeach\Model\Course;
use App\Module\BakeTeach\Model\CourseAttachment;
use App\Module\BakeTeach\Model\CourseGift;
use App\Module\BakeTeach\Model\CourseLesson;
use App\Module\BakeTeach\Model\CourseLike;
use App\Module\BakeTeach\Model\CourseProduct;
use App\Module\BakeTeach\Model\CourseSchedule;
use App\Module\BakeTeach\Model\CourseSection;
use App\Module\BakeTeach\Model\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Package\Exception\HttpException;

class CourseRepository
{
    public $helper;
    public $studentRepository;
    public $ratingRepository;
    public $assignmentRepository;

    public function __construct()
    {
        $this->helper = new Helper();
        $this->studentRepository = new StudentRepository();
        $this->ratingRepository = new RatingRepository();
        $this->assignmentRepository = new AssignmentRepository();
    }

    public function all($request)
    {

        if (
            isset($request['caught_by_user_id'])
            && isset($request['classification_course_app_student'])
            && isset($request['status_learning'])
        ) {
            return $this->getMyCourses($request);
        }

        $query = Course::with([
            'user_created:id,full_name,avatar',
            'user_updated:id,full_name,avatar',
            'category:id,name',
            'lecturer:id,full_name,avatar,phone,email'
        ])->withCount([
            'ratings',
            'invoicesPaid as students_registered_count',
            'invoicesPaid as students_count' => function ($query) {
                $query->select(DB::raw('COUNT(DISTINCT user_id)'));
            }
        ]);

        $query = $this->queryApplyFilter($query, $request);
        $query->orderBy('created_at', 'desc');

        $data = isset($request['include_id']) ? $query->get() : $query->paginate($request['limit'] ?? 10);
        //
        if (isset($request['include_id'])) {
            $this->helper->includeAdapter(
                $data,
                'id',
                Course::class,
                $request['include_id'],
                [
                    'user_created:id,full_name,avatar',
                    'user_updated:id,full_name,avatar',
                    'category:id,name'
                ]
            );
            $data = $this->helper->paginateCustom($data);
        }
        //
        return $data;
    }

    private function getMyCourses($request)
    {
        $user_id = $request['caught_by_user_id'];
        // Subquery để lấy course_invoice mới nhất cho từng course
        $latestCourseInvoices = DB::table('course_invoices')
            ->select('course_invoices.*')
            ->whereRaw(
                'course_invoices.id IN (
            SELECT MAX(id)
            FROM course_invoices
            WHERE user_id = ' . $user_id . '
            GROUP BY course_id
        )'
            );

        // Subquery để lấy invoice mới nhất cho từng course_invoice
        $latestInvoices = DB::table('invoices')
            ->select('invoices.*')
            ->whereRaw(
                "invoices.id IN (
            SELECT MAX(id)
            FROM invoices
            WHERE user_id = $user_id
            AND invoice_type = 'invoice_course'
            AND status = 'approved'
            GROUP BY invoice_id
        )"
            );

        // Truy vấn chính với các điều kiện join và lọc
        $query = DB::table('courses')
            ->joinSub($latestCourseInvoices, 'course_invoices', function ($join) {
                $join->on('courses.id', '=', 'course_invoices.course_id');
            })
            ->joinSub($latestInvoices, 'invoices', function ($join) {
                $join->on('invoices.invoice_id', '=', 'course_invoices.id');
            })
            ->join('course_invoice_schedules', 'course_invoice_schedules.invoice_id', '=', 'course_invoices.id')
            ->join('users as lecturer', 'lecturer.id', '=', 'course_invoices.lecturer_id')
            ->when(isset($request['caught_by_user_id']), function ($query) use ($request) {
                return $query->where(function ($query) use ($request) {
                    $query->where('invoices.user_id', $request['caught_by_user_id']);
                })->when(isset($request['classification_course_app_student']), function ($query) use ($request) {
                    return $query->when($request['classification_course_app_student'] == 'offline', function ($query) use ($request) {
                        return $query->where('course_invoices.classification', 'offline')
                            ->when(
                                isset($request['status_learning']),
                                function ($query) use ($request) {
                                    return $query->where(function ($query) use ($request) {
                                        $query->where('course_invoices.status_complete', $request['status_learning']);
                                    });
                                }
                            );
                    }, function ($query) use ($request) {
                        return $query->where('course_invoices.classification', 'online')
                            ->when(
                                isset($request['status_learning']),
                                function ($query) use ($request) {
                                    return $query->where(function ($query) use ($request) {
                                        $today = Carbon::today();
                                        $current_time = Carbon::now()->format('H:i:s');
                                        // KH đang diễn ra
                                        $query->when($request['status_learning'] == 'in_progress', function ($query) use ($request, $today, $current_time) {
                                            return $query->where(function ($query) use ($request, $today, $current_time) {
                                                // Lấy các record mà ngày đã qua (nhỏ hơn ngày hôm nay)
                                                $query->where(function ($query) use ($today, $current_time) {
                                                    // Trường hợp 1: Cùng ngày hôm nay và trong khoảng start_time và end_time
                                                    $query->whereDate('course_invoice_schedules.date', $today)
                                                        ->whereTime('course_invoice_schedules.start_time', '<=', $current_time)
                                                        ->whereTime('course_invoice_schedules.end_time', '>=', $current_time);
                                                })
                                                    ->orWhere(function ($query) use ($today, $current_time) {
                                                        // Trường hợp 2: Cùng ngày hôm nay nhưng thời gian hiện tại đã lớn hơn end_time
                                                        $query->whereDate('course_invoice_schedules.date', $today)
                                                            ->whereTime('course_invoice_schedules.end_time', '<', $current_time);
                                                    })
                                                    ->orWhere(function ($query) use ($today) {
                                                        // Trường hợp 3: Ngày hiện tại lớn hơn date (record đã qua ngày)
                                                        $query->whereDate('course_invoice_schedules.date', '<', $today);
                                                    });
                                            });
                                        });
                                        // KH chưa diễn ra
                                        $query->when($request['status_learning'] == 'not_yet_occurred', function ($query) use ($request, $today, $current_time) {
                                            return $query->where(function ($query) use ($request, $today, $current_time) {
                                                // Lấy các record chưa tới ngày hôm nay
                                                $query->whereDate('course_invoice_schedules.date', '>', $today)
                                                    // Hoặc là cùng ngày nhưng giờ hiện tại nhỏ hơn start_time
                                                    ->orWhere(function ($subquery) use ($today, $current_time) {
                                                        $subquery->whereDate('course_invoice_schedules.date', $today)
                                                            ->whereTime('course_invoice_schedules.start_time', '>', $current_time);
                                                    });
                                            });
                                        });
                                        // KH đã hoàn thành
                                        $query->when($request['status_learning'] == 'completed', function ($query) use ($request, $today, $current_time) {
                                            return $query->where(function ($query) use ($request, $today, $current_time) {
                                                $query->where('course_invoices.status_complete', 'completed');
                                            });
                                        });
                                    });
                                }
                            );
                    });
                });
            });

        $query->select([
            'courses.id',
            'courses.name',
            'courses.image',
            'course_invoices.classification',
            'course_invoices.type',
            'course_invoices.id as ci_id',
            DB::raw("CONCAT('" . url('/') . "', courses.image) as image_url"),
            DB::raw('JSON_OBJECT(
            "id", lecturer.id,
            "full_name", lecturer.full_name,
            "avatar", lecturer.avatar
        ) as lecturer'),
        ]);
        $query->addSelect([
            'is_course_rated' => DB::raw(
                'CASE
              WHEN EXISTS (
                  SELECT 1
                  FROM ratings
                  WHERE ratings.object_id = courses.id
                  AND ratings.object_type = "course"
                  AND ratings.user_id = ' . $user_id . '
              )
              THEN TRUE
              ELSE FALSE
           END as is_course_rated'
            )
        ]);

        $query->orderBy('course_invoices.created_at', 'desc');

        $data = $query->paginate($request['limit'] ?? 10);
        $data->getCollection()->transform(function ($item) {
            $item->lecturer = json_decode($item->lecturer, true);
            return $item;
        });
        return $data;
    }

    private function queryApplyFilter($query, $request, $flag = true)
    {
        return $query->when(isset($request['keyword']), function ($query) use ($request) {
            return $query->where(function ($query) use ($request) {
                $query->where('name', 'LIKE', "%" . $request['keyword'] . "%");
                $query->orWhere('code', 'LIKE', "%" . $request['keyword'] . "%");
            });
        })->when(isset($request['category_id']), function ($query) use ($request) {
            return $query->where('category_id', $request['category_id']);
        })->when(isset($request['type']), function ($query) use ($request) {
            return $query->where('type', $request['type']);
        })->when(isset($request['status']), function ($query) use ($request) {
            return $query->when(auth()->guard('api')->user()->type === 'lecturer', function ($query) use ($request) {
                return $query->when($request['status'] === 'inactive', function ($query) use ($request) {
                    return $query->where('is_active', '!=', 1);
                }, function ($query) use ($request) {
                    return $query->where('status', $request['status']);
                });
            }, function ($query) use ($request) {
                return $query->where('status', $request['status']);
            });
        })->when(isset($request['is_active']), function ($query) use ($request) {
            return $query->where('is_active', 1);
        })->when(isset($request['lecturer_id']), function ($query) use ($request) {
            return $query->where('lecturer_id', $request['lecturer_id']);
        })->when(isset($request['from_price']), function ($query) use ($request) {
            return $query->where('price', '>=', $request['from_price']);
        })->when(isset($request['to_price']), function ($query) use ($request) {
            return $query->where('price', '<=', $request['to_price']);
        })->when(isset($request['except_requesting_invoice']) && explode(",", $request['except_requesting_invoice'])[1] === 'create', function ($query) use ($request) {
            return $query->whereDoesntHave('requestingInvoiceOfStudent', function ($query) use ($request) {
                $query->where('user_id', explode(",", $request['except_requesting_invoice'])[0]);
            });
        })->when(isset($request['except_type_request']), function ($query) use ($request) {
            $typeActiveMapping = [
                'open' => 1,
                'close' => 0
            ];
            return $query->where('is_active', '!=', @$typeActiveMapping[$request['except_type_request']]);
        })->when($flag, function ($query) use ($request) {
            return $query->when(isset($request['classification']), function ($query) use ($request) {
                return $query->where('classification', $request['classification']);
            });
        });
    }

    public function summaryList($request)
    {
        $filed = 'classification';
        if (auth()->guard('api')->user()->type === 'lecturer') {
            $filed = 'status';
        }
        $summaryList = Course::select([
            $filed,
            DB::raw('COUNT(*) as total_count')
        ]);
        $summaryList = $this->queryApplyFilter($summaryList, $request, false);
        $summaryList = $summaryList->groupBy($filed)->get();
        $totalCount = $summaryList->sum('total_count');
        $summaryList->push([
            $filed => $filed === 'status' ? 'inactive' : 'all',
            'total_count' => $filed === 'status' ? DB::table('courses')->whereNull('deleted_at')->where('is_active', '!=', 1)->count() : $totalCount,
        ]);

        return $summaryList;
    }

    public function summaryDetail($request)
    {
        $courseId = $request['course_id'];

        $counts = DB::select(
            'SELECT
          (SELECT COUNT(*) FROM course_sections WHERE course_id = ?) AS lessons_count,
          (SELECT COUNT(*) FROM course_schedules WHERE course_id = ?) AS schedules_count,
          (SELECT COUNT(*) FROM course_attachments WHERE course_id = ?) AS attachments_count',
            [$courseId, $courseId, $courseId]
        );

        $counts = (array)$counts[0] ?? [];

        $productTypes = ['ingredient', 'device', 'tool'];

        $summary = array_merge($counts, [
            'gifts_count' => $this->listGift($request->merge(['get_count' => 1])->all()),
            'student_count' => $this->getStudentCount($courseId),
            'rating_count' => $this->getRatingCount($request),
            'exercise_count' => $this->getAssignmentCount($request, 'exercise'),
            'finished_product_count' => $this->getAssignmentCount($request, 'finished_product')
        ]);

        foreach ($productTypes as $type) {
            $summary["{$type}_product_count"] = $this->listProduct([
                'course_id' => $courseId,
                'key_default' => $type,
                'get_count' => 1,
            ]);
        }

        return $summary;
    }

    private function getStudentCount($courseId)
    {
        return $this->studentRepository->all([
            'course_id' => $courseId,
            'get_count' => 1
        ]);
    }

    private function getRatingCount($request)
    {
        return $this->ratingRepository->all($request->merge(['get_count' => 1])->all());
    }

    private function getAssignmentCount($request, $type)
    {
        $assignmentRequest = clone $request;
        $assignmentRequest->merge([
            'type_assignment' => $type,
            'get_count' => 1
        ]);
        return $this->assignmentRepository->all($assignmentRequest);
    }

    public function detailSection($id)
    {
        return CourseSection::findOrFail($id);
    }

    public function detailLesson($id)
    {
        return CourseLesson::findOrFail($id);
    }

    public function listSection($request)
    {
        return CourseSection::where('course_id', $request['course_id'])->get();
    }

    public function createSection($data = [])
    {
        return CourseSection::create($data);
    }

    public function updateSection($data = [])
    {
        $detail = CourseSection::findOrFail($data['id']);
        $detail->update($data);
        return $detail;
    }

    public function deleteSection($id)
    {
        $detail = CourseSection::findOrFail($id);
        $detail->delete();
        return $detail;
    }

    public function listLesson($request)
    {
        return CourseLesson::where('section_id', $request['section_id'])->get();
    }

    public function createLesson($data = [])
    {
        return CourseLesson::create($data);
    }

    public function updateLesson($data = [])
    {
        $detail = CourseLesson::findOrFail($data['id']);
        $detail->update($data);
        return $detail;
    }

    public function deleteLesson($id)
    {
        $detail = CourseLesson::findOrFail($id);
        $detail->delete();
        return $detail;
    }

    public function find($id)
    {
        $data = Course::with([
            'user_created:id,full_name,avatar',
            'user_updated:id,full_name,avatar',
            'lecturer:id,full_name,avatar,phone,email',
            'category:id,name'
        ])->withCount([
            'ratings',
            'getTotalVideoUploadLessons as count_videos',
            'invoicesPaid as students_count' => function ($query) {
                $query->select(DB::raw('COUNT(DISTINCT user_id)'));
            },
            'invoicesPaid as students_registered_count',
            'assignments'
        ])->withAvg('ratings as average_rating', 'star_count')
            ->withTrashed()->findOrFail($id);

        $user = auth()->guard('api')->user();

        if ($user->type !== 'admin') {
            // Kiểm tra xem người dùng đã xem khóa học này trong 1 giờ qua chưa
            $viewedRecently = DB::table('course_views')
                ->where('user_id', $user->id)
                ->where('course_id', $id)
                ->exists();

            if (!$viewedRecently) {
                // Mỗi user chỉ tính 1 lượt 1 lần
                $data->views++;
                $data->save();

                // Lưu thông tin lượt xem
                DB::table('course_views')->insert([
                    'user_id' => $user->id,
                    'course_id' => $id,
                    'viewed_at' => now(),
                ]);
            }
        }

        return $data;
    }


    public function create($data = [])
    {
        return Course::create($data);
    }

    public function update($data)
    {
        $course = Course::findOrFail($data['id']);
        $course->update($data);
        return $course;
    }

    public function delete($id)
    {
        $data = Course::findOrFail($id);
        if ($data->is_active == 1) {
            throw new HttpException("Không thể xóa khóa học đang hoạt động !");
        }
        $data->delete();
        return $data;
    }

    public function createGifts($gifts = [])
    {
        return DB::table('course_gifts')->insert($gifts);
    }

    public function updateGift($request)
    {
        $data = CourseGift::findOrFail($request['id']);
        $data->update($request);
        return $data;
    }

    public function createProducts($products = [])
    {
        return DB::table('course_products')->insert($products);
    }

    public function listGift($request)
    {
        $query = CourseGift::with([
            'product:id,name,image,unit_key,unit_price,promotional_price,category_id',
            'product.category:id,name'
        ])->where('course_id', $request['course_id']);
        return !isset($request['get_count']) ? $query->paginate($request['limit'] ?? 10) : $query->count();
    }

    public function detailGift($id)
    {
        return CourseGift::findOrFail($id);
    }

    public function createGift($data = [])
    {
        return CourseGift::insert($data);
    }

    public function deleteGift($id)
    {
        $detail = CourseGift::findOrFail($id);
        $detail->delete();
        return $detail;
    }

    public function listProduct($request)
    {
        if (!isset($request['key_default'])) {
            $request['key_default'] = 'ingredient';
        }
        $query = CourseProduct::with([
            'product:id,name,image,unit_key,unit_price,promotional_price,category_id',
            'product.category:id,name,key_default'
        ])->where('course_id', $request['course_id'])
            ->where(function ($query) use ($request) {
                $query->whereHas('product.category.parent', function ($query) use ($request) {
                    $query->where('key_default', $request['key_default']);
                });
            });
        return !isset($request['get_count']) ? $query->paginate($request['limit'] ?? 10) : $query->count();
    }

    public function createProduct($data = [])
    {
        return CourseProduct::insert($data);
    }

    public function deleteProduct($id)
    {
        $detail = CourseProduct::findOrFail($id);
        $detail->delete();
        return $detail;
    }

    public function approve($id)
    {
        return $this->changeStatus($id);
    }

    public function reject($id)
    {
        return $this->changeStatus($id, 'reject');
    }

    private function changeStatus($id, $action = 'approve')
    {
        $course = Course::findOrFail($id);
        $course->status = $action;
        $course->save();
        return $course;
    }

    public function listAttachment($request)
    {
        return CourseAttachment::where('course_id', $request['course_id'])->orderBy('created_at', 'desc')->get();
    }

    public function listSchedule($request)
    {
        $query = CourseSchedule::where('course_id', $request['course_id'])
            ->orderBy('created_at', 'desc');
        //
        $data = isset($request['include_id']) ? $query->get() : $query->paginate($request['limit'] ?? 10);
        //
        if (isset($request['include_id'])) {
            $this->helper->includeAdapter(
                $data,
                'id',
                CourseSchedule::class,
                $request['include_id'],
                []
            );
            $data = $this->helper->paginateCustom($data);
        }
        //
        return $data;
    }

    public function createAttachments($attachments = [])
    {
        return DB::table('course_attachments')->insert($attachments);
    }

    public function createSchedules($schedules = [])
    {
        return DB::table('course_schedules')->insert($schedules);
    }

    public function detailSchedule($id)
    {
        return CourseSchedule::findOrFail($id);
    }

    public function updateSchedule($data = [])
    {
        $detail = CourseSchedule::findOrFail($data['id']);
        $detail->update($data);
        return $detail;
    }

    public function deleteSchedule($id)
    {
        $detail = CourseSchedule::findOrFail($id);
        $detail->delete();
        return $detail;
    }

    public function deleteAttachment($id)
    {
        $detail = CourseAttachment::findOrFail($id);
        $detail->delete();
        return $detail;
    }

    public function like($id)
    {
        $userId = auth()->guard('api')->id();
        return CourseLike::updateOrCreate(['user_id' => $userId, 'course_id' => $id], ['user_id' => $userId, 'course_id' => $id, 'liked_at' => now()->format('Y-m-d H:i:s'), 'created_at' => now()->format('Y-m-d H:i:s')]);
    }

    public function unlike($id)
    {
        $userId = auth()->guard('api')->id();
        return CourseLike::where(['user_id' => $userId, 'course_id' => $id])->delete();
    }
}
