<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon;

class Voucher extends Model
{
    use SoftDeletes;
    use Traits\EloquentGetTableNameTrait;
    public $timestamps = true;
    public $guarded = ['id'];
    protected $fillable = ['user_id','affiliate_id', 'voucher_type_id','code','total','payment_date','paid_amount','bank','bank_pay_number', 'payable_id', 'payable_type', 'payment_type_id'];

    function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        if (!$this->code) {
            $latest_vouchers = DB::table('vouchers')->orderBy('created_at', 'desc')->limit(1)->first();
            if (!$latest_vouchers) $latest_vouchers = (object)['id' => 0];
            $this->code = implode(['TRANS', str_pad($latest_vouchers->id + 1, 6, '0', STR_PAD_LEFT), '-', Carbon::now()->year]);
        }
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function payment_type()
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function voucher_type()
    {
        return $this->belongsTo(VoucherType::class);
    }
}
