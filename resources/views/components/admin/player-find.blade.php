@props(['find' => '', 'more' => false, 'placeholder' => null])
{{-- narrows a list of players (App\Services\Admin\PlayerChoices) by the start of the name --}}
<div class="input-group input-group-sm mb-2">
    <input type="search" class="form-control" value="{{ $find }}" placeholder="{{ $placeholder ?? __('admin/global.player_find') }}"
        onkeydown="if (event.key === 'Enter') { event.preventDefault(); this.nextElementSibling.firstElementChild.click(); }">
    <div class="input-group-append">
        <button type="button" class="btn btn-outline-secondary"
            onclick="const url = new URL(window.location.href); url.searchParams.set('find', this.parentElement.previousElementSibling.value); window.location.href = url.toString();">
            <i class="fas fa-search"></i>
        </button>
    </div>
</div>
@if ($more)
    <small class="form-text text-muted mb-2">{{ __('admin/global.player_find_more', ['count' => \App\Services\Admin\PlayerChoices::LIMIT]) }}</small>
@endif
