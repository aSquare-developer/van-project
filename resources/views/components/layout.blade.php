<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Van Project' }} · Van Project</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto max-w-5xl px-4 py-10">
        @if(session('status'))<p role="status" class="mb-6 rounded-lg bg-emerald-100 p-4 text-emerald-900">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="mb-6 rounded-lg bg-red-100 p-4 text-red-900"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </main>
</body>
</html>
