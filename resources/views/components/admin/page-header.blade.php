@props(['title', 'description' => null])

<header {{ $attributes->merge(['class' => 'page-head']) }}>
    <div>
        <h2>{{ $title }}</h2>
        @if($description)
            <p>{{ $description }}</p>
        @endif
    </div>
    @if(isset($actions))
        <div class="head-actions">
            {{ $actions }}
        </div>
    @endif
</header>
