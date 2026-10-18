<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Widgets;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use AlfaSix\AlfakitForms\Models\Form;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * Views, submissions (with the last 14 days as a sparkline), conversion and spam of the
 * form on the page (the record of a form's view page).
 */
class FormStatsWidget extends StatsOverviewWidget
{
    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'xl' => 4];

    protected function getStats(): array
    {
        $form = $this->getRecord();

        if (! $form instanceof Form) {
            return [];
        }

        $submissions = $form->countSubmissions();
        $spam = $form->submissions()->where('is_spam', true)->count();
        $locale = app()->getLocale();

        return [
            Stat::make(__('alfakit-forms::forms.stats.views'), Number::format($form->views_count, locale: $locale))
                ->icon('eye'),
            Stat::make(__('alfakit-forms::forms.stats.submissions'), Number::format($submissions, locale: $locale))
                ->icon('inbox')
                ->description(__('alfakit-forms::forms.stats.last_days', ['days' => 14]))
                ->chart($this->getDailySubmissions($form, 14))
                ->color('primary'),
            Stat::make(__('alfakit-forms::forms.stats.conversion'), Number::percentage($form->getConversionRate($submissions), maxPrecision: 1, locale: $locale))
                ->icon('trending-up')
                ->description(__('alfakit-forms::forms.stats.conversion_description')),
            Stat::make(__('alfakit-forms::forms.stats.spam'), Number::format($spam, locale: $locale))
                ->icon('shield-alert')
                ->color($spam > 0 ? 'warning' : null),
        ];
    }

    /**
     * Submissions per day, oldest first.
     *
     * @return array<int, int>
     */
    protected function getDailySubmissions(Form $form, int $days): array
    {
        $since = Carbon::now()->startOfDay()->subDays($days - 1);
        $counts = array_fill(0, $days, 0);

        $dates = $form->submissions()
            ->where('is_spam', false)
            ->where('created_at', '>=', $since)
            ->pluck('created_at');

        foreach ($dates as $date) {
            $index = (int) $since->diffInDays(Carbon::parse($date)->startOfDay());

            if ($index >= 0 && $index < $days) {
                $counts[$index]++;
            }
        }

        return $counts;
    }
}
