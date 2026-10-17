@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('images/email-logo.png') }}" class="logo" alt="{{ config('app.name') }}"><br>
{!! $slot !!}
</a>
</td>
</tr>
