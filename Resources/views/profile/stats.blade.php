@push('header')
    @at(mm('Stats', 'Resources/assets/scss/profile_stats.scss'))
@endpush

@push('profile_body')
    <div class="row gx-3 gy-3">
        <div class="col-md-{{ sizeof($servers) > 1 ? 9 : 12 }}">
            @if (!empty($stats))
                <div class="row gx-3 gy-3">
                    @foreach ($blocks as $key => $block)
                        <div class="col-md-4">
                            <div class="profile-stat-block">
                                <i class="ph {{ $block['icon'] }}"></i>

                                <div>
                                    <p>{{ __($block['text']) }}</p>
                                    <div>{{ is_int($stats['stats'][$key]) ? round($stats['stats'][$key], 2) : $stats['stats'][$key] }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <h3 class="text-center">@t('stats.profile.no_info')</h3>
            @endif
        </div>

        @if (sizeof($servers) > 1)
            <div class="col-md-3">
                <div class="servers servers-block">
                    <h3>@t('stats.choose_server')</h3>
                    <div class="servers-block-container">
                        @foreach ($servers as $key => $server)
                            <a href="{{ url('profile/' . $user->id)->addParams(['sid' => $key, 'tab' => 'stats']) }}"
                                class="servers-block-btn @if (isset($server['current'])) selected @endif">
                                {{ $server['server']->name }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
@endpush
