<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cast extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'profile_path',
    ];

    /**
     * Get the movies for the cast member.
     */
    public function movies()
    {
        return $this->belongsToMany(Movie::class, 'cast_movie')
            ->withPivot('character', 'order');
    }
}
