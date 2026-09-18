<?php
namespace App\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use Notifiable, MustVerifyEmailTrait;

    protected $fillable=['role','first_name','last_name','birth_date','email','password','status','deactivated_at','deactivation_reason'];
    protected $hidden=['password','remember_token'];

    protected function casts(): array
    {
        return [
            'birth_date'=>'date',
            'email_verified_at'=>'datetime',
            'deactivated_at'=>'datetime',
            'password'=>'hashed',
        ];
    }

    public function profile(): HasOne { return $this->hasOne(UserProfile::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function walletAccount(): HasOne { return $this->hasOne(WalletAccount::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
    public function waitlistEntries(): HasMany { return $this->hasMany(OfferWaitlistEntry::class); }
    public function documentConsents(): HasMany { return $this->hasMany(DocumentConsent::class); }
    public function warnings(): HasMany { return $this->hasMany(UserWarning::class); }
    public function restrictions(): HasMany { return $this->hasMany(UserRestriction::class); }
    public function userNotifications(): HasMany { return $this->hasMany(UserNotification::class); }
    public function pushSubscriptions(): HasMany { return $this->hasMany(PushSubscription::class); }
    public function payouts(): HasMany { return $this->hasMany(PayoutRequest::class); }
    public function privacyRequests(): HasMany { return $this->hasMany(PrivacyRequest::class); }
    public function reliabilityEvents(): HasMany { return $this->hasMany(ReliabilityEvent::class); }

    public function isAdmin(): bool
    {
        return $this->role==='admin';
    }


    public function hasRestriction(string $type): bool
    {
        return $this->restrictions()->current()->whereIn('type',[$type,'account'])->exists();
    }

    public function isOfferBlocked(int $offerId): bool
    {
        return $this->restrictions()
            ->current()
            ->get()
            ->contains(function($restriction) use($offerId){
                $blocked=$restriction->blocked_offer_ids;
                return is_array($blocked) && in_array($offerId,array_map('intval',$blocked),true);
            });
    }

    public function effectiveOrderLimit(): int
    {
        $limits=$this->restrictions()
            ->current()
            ->whereNotNull('max_active_orders')
            ->pluck('max_active_orders')
            ->map(fn($value)=>(int)$value)
            ->filter(fn($value)=>$value>=0);

        return $limits->isEmpty()?5:max(0,(int)$limits->min());
    }
}
