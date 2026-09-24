@props(['url', 'text'])
<a href="{{ $url }}"@if (\App\Support\Format::isHttp($url)) target="_blank" rel="noopener noreferrer"@endif>{{ $text }}</a>