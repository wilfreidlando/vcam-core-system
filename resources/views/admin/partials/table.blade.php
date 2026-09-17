@props([
    'headers' => [],
    'rows' => [],
    'empty' => 'No results.',
])

@php
    $headers = collect($headers);
    $rows = collect($rows);
@endphp

<div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
    <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
            <tr>
                @foreach ($headers as $label)
                    <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        {{ $label }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
            @forelse ($rows as $row)
                <tr class="hover:bg-slate-50 transition-colors">
                    {{ $row }}
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $headers->count() }}" class="px-4 py-6 text-center text-sm text-slate-500">
                        {{ $empty }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
