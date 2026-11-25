<div class="head">
    <div class="head-row">
        <div class="head-side">
            @if ($school['logo'])
                <img class="logo" src="{{ asset('storage/' . ltrim($school['logo'], '/')) }}" alt="">
            @endif
        </div>
        <div class="head-mid">
            <div class="school">{{ $school['name'] }}</div>
            @if ($school['address'] || $school['phone'])
                <div class="contact">{{ collect([$school['address'], $school['phone'] ? 'Tel: ' . $school['phone'] : null, $school['email']])->filter()->implode('  ·  ') }}</div>
            @endif
        </div>
        <div class="head-side right">
            @if (! empty($withPhoto))
                @if (! empty($photo))
                    <img class="photo" src="{{ asset('storage/' . ltrim($photo, '/')) }}" alt="">
                @else
                    <div class="photo empty">Photo</div>
                @endif
            @endif
        </div>
    </div>
    <div class="title">{{ $title }}</div>
</div>
