@extends('layouts.admin')

@section('title', 'Project')

@section('content')
<div class="space-y-6">
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">ID</p>
                <p class="font-mono text-slate-700 break-all">{{ $project->id }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">Organization</p>
                <p class="font-mono text-slate-700 break-all">{{ $project->organizationId }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">Name</p>
                <p class="text-slate-900">{{ $project->name }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Status</p>
                <p>
                    <span @class([
                        'inline-flex rounded-md px-2 py-0.5 text-xs font-mono',
                        'bg-emerald-100 text-emerald-700' => $project->status === 'active',
                        'bg-slate-100 text-slate-700' => $project->status !== 'active',
                    ])>
                        {{ $project->status }}
                    </span>
                </p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <a href="{{ route('admin.payments.index', ['project_id' => $project->id]) }}"
           class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <span class="font-medium text-slate-900">This project's payments</span>
            <span class="text-indigo-600">→</span>
        </a>
        <a href="{{ route('admin.projects.wallets', ['projectId' => $project->id]) }}"
           class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <span class="font-medium text-slate-900">This project's wallets</span>
            <span class="text-indigo-600">→</span>
        </a>
    </div>

    <div class="text-right">
        <a href="{{ route('admin.projects.index', ['organization_id' => $project->organizationId]) }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
            ← Back to projects
        </a>
    </div>
</div>
@endsection
