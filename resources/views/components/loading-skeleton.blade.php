@props(['type' => 'list', 'count' => 3])

@if($type === 'list')
    <div {{ $attributes->merge(['class' => 'space-y-3']) }}>
        @for($i = 0; $i < $count; $i++)
            <div class="bg-white rounded-lg shadow-md p-4 animate-pulse">
                <div class="flex items-start gap-4">
                    <div class="w-5 h-5 bg-gray-200 rounded mt-1"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-4 bg-gray-200 rounded w-3/4"></div>
                        <div class="h-3 bg-gray-200 rounded w-1/2"></div>
                        <div class="h-2 bg-gray-200 rounded w-1/4"></div>
                    </div>
                    <div class="w-16 h-8 bg-gray-200 rounded"></div>
                </div>
            </div>
        @endfor
    </div>
@elseif($type === 'card')
    <div {{ $attributes->merge(['class' => '']) }}>
        <div class="bg-white rounded-lg shadow-md p-6 animate-pulse">
            <div class="space-y-4">
                <div class="h-6 bg-gray-200 rounded w-3/4"></div>
                <div class="h-4 bg-gray-200 rounded w-full"></div>
                <div class="h-4 bg-gray-200 rounded w-5/6"></div>
            </div>
        </div>
    </div>
@elseif($type === 'table')
    <div {{ $attributes->merge(['class' => '']) }}>
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="animate-pulse">
                @for($i = 0; $i < $count; $i++)
                    <div class="border-b border-gray-200 p-4">
                        <div class="flex items-center gap-4">
                            <div class="h-4 bg-gray-200 rounded w-1/4"></div>
                            <div class="h-4 bg-gray-200 rounded w-1/3"></div>
                            <div class="h-4 bg-gray-200 rounded w-1/5"></div>
                        </div>
                    </div>
                @endfor
            </div>
        </div>
    </div>
@else
    <!-- Custom/default skeleton -->
    <div {{ $attributes->merge(['class' => '']) }}>
        <div class="animate-pulse space-y-3">
            @for($i = 0; $i < $count; $i++)
                <div class="h-16 bg-gray-200 rounded-lg"></div>
            @endfor
        </div>
    </div>
@endif
