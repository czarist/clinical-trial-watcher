<x-mail::message>
# {{ $content['title'] ?? '' }}

{{ $content['period']['label'] ?? '' }}

{{ $content['intro'] ?? '' }}

@foreach (($content['items'] ?? []) as $item)
---

## {{ $item['nct_id'] ?? '' }}: {{ $item['title'] ?? ($content['labels']['missing_fact'] ?? '') }}

{{ $item['summary'] }}

@foreach (($item['facts'] ?? []) as $fact)
- {{ $fact }}
@endforeach

@foreach (($item['reasons'] ?? []) as $reason)
*{{ $reason }}*<br>
@endforeach

{{ str_replace(':date', $item['update_date_label'] ?? ($content['labels']['missing_fact'] ?? ''), $content['labels']['updated_on'] ?? '') }}
@if ($item['url'])
· [{{ $content['labels']['view_registry'] ?? 'ClinicalTrials.gov' }}]({{ $item['url'] }})
@endif

<small>{{ ($item['summary_source'] ?? '') === 'ai' ? ($content['labels']['source_ai'] ?? '') : ($content['labels']['source_factual'] ?? '') }}</small>

@endforeach

<x-mail::button :url="$reportUrl">
{{ trans('digest.view_report', [], $content['language'] ?? 'en') }}
</x-mail::button>

<small>{{ $content['notice'] ?? '' }}</small>

<small>{{ $content['manage'] ?? '' }}</small>
</x-mail::message>
