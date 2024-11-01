<?php

namespace App\Module\BakeTeach\Model;

use App\Model\User;
use App\Module\BakeTeach\Helpers\Helper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
  use SoftDeletes;
  public $timestamps = false;
  protected $table = 'courses';

  protected $guarded = ['id'];

  protected $casts = [
    'discount_time' => 'array'
  ];
  protected $fillable = [
    'image',
    'code',
    'name',
    'type',
    'classification',
    'price',
    'discount_price',
    'discount_time',
    'information',
    'have_limit_student',
    'limit_student',
    'status',
    'link_url',
    'password',
    'lecturer_id',
    'is_active',
    'link_url_type',
    'category_id',
    'created_by',
    'updated_by',
    'created_at',
    'updated_at',
    'deleted_at',
    'duration',
    'link_url_direct',
    'link_url_direct_type',
    'views'
  ];

  protected $appends = [
    'image_url',
    'students_count',
    'students_registered_count',
    'count_videos',
    'count_views',
    'likes',
    'is_payment_completed',
    'is_payment_pending',
    'discount_price_app',
    'is_course_completed',
    'average_rating'
  ];

  public function getAverageRatingAttribute()
  {
    $ratings = $this->ratings()->pluck('star_count');

    if ($ratings->count() > 0) {
      return round($ratings->average(), 1);
    }

    return null;
  }

  public function getDiscountPriceAppAttribute()
  {
    if (is_null($this->discount_time) && !is_null($this->discount_price)) {
      return $this->discount_price;
    } elseif (!empty($this->discount_time) && !is_null($this->discount_price)) {
      $discountTime = $this->discount_time;
      if (now()->format('Y-m-d') >= $discountTime[0] && now()->format('Y-m-d') <= $discountTime[1]) {
        return $this->discount_price;
      }
    }
    return null;
  }

  public function getIsCourseCompletedAttribute()
  {
    if (auth()->guard('api')->user()->type !== 'admin') {
      return $this->hasOne(CourseInvoice::class, 'course_id')->where('user_id', auth()->guard('api')->id())->orderBy('id', 'desc')->value('status_complete') === 'completed';
    }
    return null;
  }

  public function getIsPaymentCompletedAttribute()
  {
    return $this->recentStatusCompleteInvoice() === 'completed';
  }

  public function getIsPaymentPendingAttribute()
  {
    $statusInvoicePaymentRecent = $this->statusInvoicePaymentRecent()->first();
    if ($statusInvoicePaymentRecent) {
      return  $statusInvoicePaymentRecent->statusInvoicePaymentRecent()->value('status') === 'not_yet_paid';
    }

    return false;
  }

  public function statusInvoicePaymentRecent()
  {
    return $this->hasOne(CourseInvoice::class, 'course_id')
      ->with('statusInvoicePaymentRecent')
      ->where('user_id', auth()->guard('api')->id())
      ->orderBy('created_at', 'desc');
  }

  public function recentStatusCompleteInvoice()
  {
    return $this->hasOne(CourseInvoice::class, 'course_id')
      ->where('user_id', auth()->guard('api')->id())
      ->orderBy('created_at', 'desc')->value('status_complete');
  }

  public function getCountViewsAttribute()
  {
    return $this->views;
  }

  public function gifts()
  {
    return $this->hasMany(CourseGift::class, 'course_id');
  }

  public function getLikesAttribute()
  {
    return [
      'count_likes' => $this->hasMany(CourseLike::class, 'course_id')->count(),
      'liked' => $this->hasOne(CourseLike::class, 'course_id')->where('user_id', auth()->guard('api')->id())->count(),
    ];
  }

  public function getImageUrlAttribute()
  {
    return url($this->image);
  }

  public function getCountVideosAttribute()
  {
    $videoMain = 0;

    // if ($this->link_url) {
    //   $videoMain += 1;
    // }

    // if ($this->link_url_direct) {
    //   $videoMain += 1;
    // }
    return $this->getTotalVideoUploadLessons() + $videoMain;
  }

  public function getTotalVideoUploadLessons()
  {
    return $this->hasMany(CourseLesson::class, 'course_id')->whereNotNull('link_url')->count();
  }

  public function getStudentsCountAttribute()
  {
    return $this->invoicesPaid()->distinct('user_id')->count();
  }

  public function getStudentsRegisteredCountAttribute()
  {
    return $this->invoicesPaid()->count();
  }

  public function lecturer()
  {
    return $this->belongsTo(User::class, 'lecturer_id')->withTrashed();
  }

  public function category()
  {
    return $this->belongsTo(CourseCategory::class, 'category_id')->withTrashed();
  }

  public function user_created()
  {
    return $this->belongsTo(User::class, 'created_by')->withTrashed();
  }

  public function user_updated()
  {
    return $this->belongsTo(User::class, 'updated_by')->withTrashed();
  }

  public function sections()
  {
    return $this->hasMany(CourseSection::class, 'course_id');
  }

  public function requestingInvoiceOfStudent()
  {
    return $this->hasMany(CourseInvoice::class, 'course_id')->whereHas('statusInvoicePaymentRecent', function ($query) {
      $query->where('status', 'not_yet_paid');
    });
  }

  public function invoicesPaid()
  {
    return $this->hasMany(CourseInvoice::class, 'course_id')->whereHas('statusInvoicePaymentRecent', function ($query) {
      $query->where('status', 'approved');
    });
  }

  public function invoiceCourseRecentUser()
  {
    return $this->hasOne(CourseInvoice::class, 'course_id')->where('user_id', request()->caught_by_user_id)->orderBy('id', 'desc');
  }

  public function ratings()
  {
    return $this->hasMany(Rating::class, 'object_id')->where('object_type', 'course');
  }
}
