<?php
namespace App\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use Notifiable, MustVerifyEmailTrait;

    protected $fillable=['role','first_name','last_name','birth_date','email','password','status','verified_at'];
    protected $hidden=['password','remember_token'];

    protected function casts(): array
    {
        return [
            'birth_date'=>'date',
            'email_verified_at'=>'datetime',
            'verified_at'=>'datetime',
            'password'=>'hashed',
        ];
    }

    public function profile(): HasOne { return $this->hasOne(UserProfile::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function walletAccount(): HasOne { return $this->hasOne(WalletAccount::class); }
    public function verifications(): HasMany { return $this->hasMany(IdentityVerification::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
    public function documentConsents(): HasMany { return $this->hasMany(DocumentConsent::class); }
    public function warnings(): HasMany { return $this->hasMany(UserWarning::class); }
    public function restrictions(): HasMany { return $this->hasMany(UserRestriction::class); }
    public function userNotifications(): HasMany { return $this->hasMany(UserNotification::class); }
    public function payouts(): HasMany { return $this->hasMany(PayoutRequest::class); }
    public function loginChallenges(): HasMany { return $this->hasMany(LoginChallenge::class); }

    public function isAdmin(): bool
    {
        return in_array($this->role,['admin','superadmin','staff','accounting'],true);
    }

    public function hasPermission(string $permission): bool
    {
        if($this->role==='superadmin') return true;
        if(!$this->isAdmin()) return false;

        return DB::table('role_permissions')
            ->join('permissions','permissions.id','=','role_permissions.permission_id')
            ->where('role_permissions.role',$this->role)
            ->where('permissions.key',$permission)
            ->exists();
    }

    public function hasRestriction(string $type): bool
    {
        return $this->restrictions()->current()->whereIn('type',[$type,'account'])->exists();
    }
}
