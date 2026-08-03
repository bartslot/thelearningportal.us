<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Models\SvgAsset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Component;

/**
 * The Icons tab of the bottom dock: the icon set that ships with the app, filed under
 * collection ▸ category ▸ subcategory and filtered by two rows of pills.
 *
 * It only ever PICKS an icon. Placing it is the scene configurator's job, reached through the
 * `svg-asset:attach` event it already listens for — so an icon becomes exactly the same layer a
 * teacher's own clipart does, with the same move / resize / reorder / delete plumbing.
 */
class IconPanel extends Component
{
    // The dock is rebuilt whenever the teacher picks another scene, so the filters are kept in
    // the session — otherwise every scene change would throw them back to the first set.

    /** Top row: which set of icons (line-art | arrows | shapes). */
    #[Session(key: 'icons.collection')]
    public string $collection = 'line-art';

    /** Second row: a whole category, or one subcategory inside it. Empty = the whole collection. */
    #[Session(key: 'icons.category')]
    public string $category = '';

    #[Session(key: 'icons.subcategory')]
    public string $subcategory = '';

    /** Icons shown at once. The grid scrolls; the dock is only a few rows tall. */
    private const PAGE = 120;

    /**
     * Reading order of the top row: the subject icons first, then the two sets that annotate
     * them. Alphabetical would open the panel on Arrows, which is not what anyone came for.
     * A collection missing from this list still shows, after the ones that are in it.
     */
    private const ORDER = ['line-art', 'arrows', 'shapes'];

    public function selectCollection(string $collection): void
    {
        if (! in_array($collection, $this->collections(), true)) {
            return;
        }

        $this->collection = $collection;
        $this->category = '';
        $this->subcategory = '';
    }

    /** A pill in the second row: a category (whole group) or one of its subcategories. */
    public function selectGroup(string $category, string $subcategory = ''): void
    {
        $this->category = $category;
        $this->subcategory = $subcategory;
    }

    /**
     * Place an icon on the selected scene, mid-stage. Dragging one onto the canvas takes the
     * same route from the browser, with the drop point attached.
     *
     * Same event the teacher's own SVG library dispatches, so an icon becomes exactly the same
     * kind of layer — nothing here knows how a layer is stored.
     */
    public function place(int $assetId): void
    {
        $this->dispatch('svg-asset:attach', assetId: $assetId);
    }

    /** @return list<string> */
    #[Computed]
    public function collections(): array
    {
        return SvgAsset::query()
            ->bundled()
            ->whereNotNull('collection')
            ->distinct()
            ->pluck('collection')
            ->sortBy($this->collectionRank(...))
            ->values()
            ->all();
    }

    /** Sortable rank: the listed collections in their listed order, then anything else A-Z. */
    private function collectionRank(string $collection): string
    {
        $i = array_search($collection, self::ORDER, true);

        return sprintf('%02d-%s', $i === false ? 99 : $i, $collection);
    }

    /**
     * The second pill row, flattened: each category followed by its own subcategories, so a
     * teacher can take a whole period or narrow to one people in a single row.
     *
     * @return list<array{category: string, subcategory: string, label: string}>
     */
    #[Computed]
    public function groups(): array
    {
        $rows = SvgAsset::query()
            ->bundled()
            ->where('collection', $this->collection)
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->orderBy('subcategory')
            ->get(['category', 'subcategory']);

        $pills = [];
        foreach ($rows as $row) {
            $category = (string) $row->category;
            if (! isset($pills[$category])) {
                $pills[$category] = ['category' => $category, 'subcategory' => '', 'label' => $category];
            }
            if ($row->subcategory) {
                $pills[$category.'/'.$row->subcategory] = [
                    'category' => $category,
                    'subcategory' => (string) $row->subcategory,
                    'label' => (string) $row->subcategory,
                ];
            }
        }

        return array_values($pills);
    }

    /** @return \Illuminate\Support\Collection<int, SvgAsset> */
    #[Computed]
    public function icons(): \Illuminate\Support\Collection
    {
        return SvgAsset::query()
            ->bundled()
            ->where('collection', $this->collection)
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when($this->subcategory !== '', fn ($q) => $q->where('subcategory', $this->subcategory))
            ->orderBy('title')
            ->limit(self::PAGE)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.wizard.icon-panel');
    }
}
