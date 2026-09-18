<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {
    use Notifiable;
    protected $fillable = ['role','first_name','last_name','birth_date','email','password','status','verified_at'];
    protected $hidden = ['password','remember_token'];
    protected function casts(): array { return ['birth_date'=>'date','email_verified_at'=>'datetime','verified_at'=>'datetime','password'=>'hashed']; }
    public function profile(): HasOne { return $this->hasOne(UserProfile::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function walletAccount(): HasOne { return $this->hasOne(WalletAccount::class); }
    public function verifications(): HasMany { return $this->hasMany(IdentityVerification::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
    public function documentConsents(): HasMany { return $this->hasMany(DocumentConsent::class); }
    public function isAdmin(): bool { return in_array($this->role, ['admin','superadmin','staff','accounting'], true); }
}
