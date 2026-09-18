<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'bank_iban'=>'encrypted',
            'bank_account_holder'=>'encrypted',
            'paypal_email'=>'encrypted',
            'paypal_name'=>'encrypted',
            'payout_details_changed_at'=>'datetime',
            'payout_name_approved_at'=>'datetime',
        ];
    }
}
