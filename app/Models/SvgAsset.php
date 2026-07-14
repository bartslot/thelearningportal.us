<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A public-domain / freely-licensed SVG a teacher imported into their library.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property string $source_ref
 * @property string $source_url
 * @property string $title
 * @property string $license
 * @property string|null $attribution
 * @property string $svg_path
 * @property int|null $width
 * @property int|null $height
 * @property string|null $view_box
 */
class SvgAsset extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'source', 'source_ref', 'source_url',
        'title', 'license', 'attribution',
        'svg_path', 'width', 'height', 'view_box',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Public URL of the stored SVG file. */
    public function url(): string
    {
        return Storage::disk('public')->url($this->svg_path);
    }

    /** Short human credit line, e.g. "Jane Roe — CC BY-SA 4.0 (Wikimedia Commons)". */
    public function credit(): string
    {
        $who = $this->attribution ? "{$this->attribution} — " : '';
        $where = match ($this->source) {
            'commons' => ' (Wikimedia Commons)',
            'freesvg' => ' (freesvg.org)',
            default => '',
        };

        return $who.$this->license.$where;
    }

    /** @param  Builder<SvgAsset>  $query */
    public function scopeOwnedBy(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }
}
