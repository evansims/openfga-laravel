@if($items->isNotEmpty())
    <ul class="openfga-menu">
        @foreach($items as $item)
            @if(isset($item['type']) && $item['type'] === 'divider')
                <li class="divider"><hr></li>
            @else
                <li class="menu-item">
                    @if($item['url'])
                        <a href="{{ $item['url'] }}" {!! $item['attributes'] ? collect($item['attributes'])->map(fn($v, $k) => "$k=\"$v\"")->implode(' ') : '' !!}>
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span class="menu-label">{{ $item['label'] }}</span>
                    @endif
                    
                    @if($item['children'] && $item['children']->isNotEmpty())
                        @include('openfga::menu', ['items' => $item['children']])
                    @endif
                </li>
            @endif
        @endforeach
    </ul>
@endif