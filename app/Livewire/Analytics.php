<?php

namespace App\Livewire;

use App\Analytics\Report;
use App\Livewire\Concerns\InteractsWithClub;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cookie-less visit analytics. Club admins see their club's traffic;
 * the platform owner can also see the whole platform (incl. public pages).
 */
#[Title('Analytics')]
class Analytics extends Component
{
    use InteractsWithClub;

    #[Url]
    public string $period = '7d';

    #[Url]
    public string $scope = 'club';

    public function mount(): void
    {
        Gate::authorize('manage', $this->club());

        if (! in_array($this->period, ['today', '7d', '30d', '90d'], true)) {
            $this->period = '7d';
        }

        if ($this->scope === 'platform' && ! $this->user()->is_super_admin) {
            $this->scope = 'club';
        }
    }

    public function render(): View
    {
        $platform = $this->scope === 'platform' && $this->user()->is_super_admin;
        $report = Report::forPeriod($platform ? null : $this->club()->id, $this->period);
        $series = $report->timeseries();

        return view('livewire.analytics', [
            'summary' => $report->summary(),
            'series' => $series,
            'maxViews' => max(1, ...array_column($series, 'views')),
            'activeNow' => $report->activeNow(),
            'feed' => $report->liveFeed(),
            'pages' => $report->breakdown('path'),
            'referrers' => $report->breakdown('referrer_host'),
            'devices' => $report->breakdown('device'),
            'browsers' => $report->breakdown('browser'),
            'systems' => $report->breakdown('os'),
            'countries' => $report->breakdown('country'),
            'languages' => $report->breakdown('locale'),
            'isSuperAdmin' => $this->user()->is_super_admin,
        ]);
    }
}
