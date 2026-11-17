<div class="grid gap-4 md:grid-cols-2">
    @foreach ([['key' => 'before', 'label' => __('До')], ['key' => 'after', 'label' => __('После')]] as $item)
        <section class="min-w-0 rounded-xl border border-gray-200 p-3 dark:border-gray-800">
            <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ $item['label'] }}</h3>
            @if ($photos[$item['key']]['preview_url'])
                <img src="{{ $photos[$item['key']]['preview_url'] }}" alt="{{ $item['label'] }}" class="max-h-[60vh] w-full rounded-lg object-contain">
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Предпросмотр недоступен. Скачайте оригинал.') }}</p>
            @endif
            <a href="{{ $photos[$item['key']]['download_url'] }}" class="mt-3 inline-flex text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('Скачать оригинал') }}</a>
        </section>
    @endforeach
</div>
