<x-mail::message>
# {{ __('alfakit-forms::forms.mail.heading', ['form' => $formTitle]) }}

@if ($submittedAt)
{{ __('alfakit-forms::forms.mail.submitted_at', ['date' => $submittedAt->toDayDateTimeString()]) }}
@endif

@forelse ($answers as $answer)
**{{ $answer['label'] }}**<br>
{{ $answer['value'] }}

@empty
{{ __('alfakit-forms::forms.mail.empty') }}
@endforelse
@if ($poweredBy ?? null)

<x-mail::subcopy>
{{ $poweredBy }}
</x-mail::subcopy>
@endif
</x-mail::message>
