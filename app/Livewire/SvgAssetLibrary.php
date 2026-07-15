<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\SvgAsset;
use App\Services\SvgAssetService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Teacher tool: search freely-licensed SVGs (Wikimedia Commons) or paste a
 * freesvg.org link, import them into a personal library, and reuse them as
 * ink-drawing subjects. All imports are sanitised and licence-stamped.
 */
class SvgAssetLibrary extends Component
{
    #[Validate('nullable|string|max:80')]
    public string $query = '';

    #[Validate('nullable|url|max:300')]
    public string $urlInput = '';

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public bool $searched = false;

    public ?string $notice = null;

    public function search(SvgAssetService $svc): void
    {
        $this->validateOnly('query');
        $this->notice = null;
        $this->results = $this->query === '' ? [] : $svc->searchCommons($this->query);
        $this->searched = true;
    }

    public function import(int $index, SvgAssetService $svc): void
    {
        $candidate = $this->results[$index] ?? null;
        if ($candidate === null) {
            return;
        }
        try {
            $asset = $svc->import(auth()->user(), $candidate);
            $this->notice = "Added “{$asset->title}” to your library.";
            unset($this->library);
        } catch (\Throwable $e) {
            report($e);
            $this->notice = 'That file could not be imported. Try another.';
        }
    }

    public function importUrl(SvgAssetService $svc): void
    {
        $this->validateOnly('urlInput');
        try {
            $asset = $svc->importFromUrl(auth()->user(), $this->urlInput);
            $this->urlInput = '';
            $this->notice = "Imported “{$asset->title}”. Check the licence before publishing.";
            unset($this->library);
        } catch (\Throwable $e) {
            report($e);
            $this->notice = 'Could not import from that link. Only freesvg.org and Wikimedia are supported.';
        }
    }

    public function remove(int $id): void
    {
        SvgAsset::ownedBy((int) auth()->id())->whereKey($id)->first()?->delete();
        unset($this->library);
    }

    public function attach(int $id): void
    {
        $asset = SvgAsset::ownedBy((int) auth()->id())->findOrFail($id);
        $this->dispatch('svg-asset:attach', assetId: $asset->id);
    }

    /** @return Collection<int, SvgAsset> */
    #[Computed]
    public function library(): Collection
    {
        return SvgAsset::ownedBy((int) auth()->id())->latest()->get();
    }

    public function render(): View
    {
        return view('livewire.svg-asset-library');
    }
}
