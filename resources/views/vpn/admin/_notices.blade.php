@if(session('warning'))
    <div class="mb-4 rounded-md bg-yellow-50 dark:bg-yellow-900/30 p-4 text-sm text-yellow-800 dark:text-yellow-200">{{ session('warning') }}</div>
@endif
@if(session('error'))
    <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/30 p-4 text-sm text-red-800 dark:text-red-200">{{ session('error') }}</div>
@endif
@if(session('success'))
    <div class="mb-4 rounded-md bg-green-50 dark:bg-green-900/30 p-4 text-sm text-green-800 dark:text-green-200">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/30 p-4 text-sm text-red-800 dark:text-red-200">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif
