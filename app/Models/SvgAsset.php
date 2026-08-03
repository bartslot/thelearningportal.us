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
 * A public-domain / freely-licensed SVG: either one a teacher imported into their own
 * library, or one of the icons that ship WITH the app.
 *
 * A bundled icon has no owner (user_id null) and is filed under collection ▸ category ▸
 * subcategory — the pills in the Icons panel. A teacher's import has an owner and no filing.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $source
 * @property string $source_ref
 * @property string|null $collection
 * @property string|null $category
 * @property string|null $subcategory
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
        'collection', 'category', 'subcategory',
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

    /**
     * The teacher's OWN imports. Deliberately excludes the bundled library, so "remove from my
     * library" can never delete an icon that ships with the app and belongs to everyone.
     *
     * @param  Builder<SvgAsset>  $query
     */
    public function scopeOwnedBy(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    /**
     * Everything this teacher may place on a scene: their own imports plus the bundled library.
     * This is the scope for reading and attaching; {@see scopeOwnedBy} is the one for deleting.
     *
     * @param  Builder<SvgAsset>  $query
     */
    public function scopeAvailableTo(Builder $query, int $userId): void
    {
        $query->where(fn (Builder $q) => $q->where('user_id', $userId)->orWhereNull('user_id'));
    }

    /** The icons that ship with the app (no owner). @param  Builder<SvgAsset>  $query */
    public function scopeBundled(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /** True for an icon that ships with the app — no teacher may delete or edit it. */
    public function isBundled(): bool
    {
        return $this->user_id === null;
    }
}
