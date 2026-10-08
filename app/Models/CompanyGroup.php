<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyGroup extends Model
{
    protected $fillable = ['code', 'name', 'status'];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_group_admins')->withTimestamps();
    }
}
