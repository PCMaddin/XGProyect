{{-- the legal pages with a text, for footers --}}
@foreach (app(\App\Services\Legal\LegalPages::class)->written() as $legalPage)
    @if (!$loop->first) · @endif<a href="{{ route('legal.show', ['page' => $legalPage, 'lang' => app()->getLocale()]) }}" target="_blank" rel="noopener">{{ __('home/legal.lg_' . $legalPage) }}</a>
@endforeach
