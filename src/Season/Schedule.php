<?php

namespace Ernestdefoe\LogoManager\Season;

use DateTimeImmutable;
use DateTimeZone;
use Ernestdefoe\LogoManager\Config;

/**
 * Decides which season, if any, is running right now.
 *
 * Rules are an ordered list and the FIRST enabled rule that matches wins.
 * Order is the administrator's, set by dragging in the studio — which means
 * overlap is a feature rather than a conflict: Christmas Day can sit above a
 * broad December "Winter" rule and take precedence for one day without either
 * needing to know about the other.
 */
class Schedule
{
    public function __construct(protected Config $config)
    {
    }

    public function active(?DateTimeImmutable $now = null): ?Season
    {
        if (! $this->config->seasonsEnabled()) {
            return null;
        }

        $tz = new DateTimeZone($this->config->timezone());
        $now ??= new DateTimeImmutable('now', $tz);
        $now = $now->setTimezone($tz);

        foreach ($this->config->seasonRules() as $rule) {
            $season = new Season($rule, $this->config);

            if ($season->enabled() && $this->matches($season, $now, $tz)) {
                return $season;
            }
        }

        return null;
    }

    protected function matches(Season $season, DateTimeImmutable $now, DateTimeZone $tz): bool
    {
        return match ($season->whenType()) {
            'feast' => $this->matchesFeast($season, $now, $tz),
            default => $this->matchesRange($season, $now),
        };
    }

    /**
     * A month/day range, which may wrap the end of the year.
     *
     * Comparing as MMDD integers keeps a wrapping range ("12-01" to "01-05")
     * a single expression instead of two date objects and a year guess.
     */
    protected function matchesRange(Season $season, DateTimeImmutable $now): bool
    {
        $from = $season->monthDay('from');
        $to = $season->monthDay('to');

        if ($from === null || $to === null) {
            return false;
        }

        $today = (int) $now->format('md');

        return $from <= $to
            ? $today >= $from && $today <= $to
            : $today >= $from || $today <= $to;
    }

    /**
     * A window around a date that moves each year.
     *
     * 🚨 The neighbouring years are checked as well as this one. A window that
     * opens ten days before Lunar New Year in late January reaches back into
     * the previous December, and on those days the only feast date that can
     * possibly match is *next* year's. Checking only the current year would
     * make the rule silently skip its own opening days.
     */
    protected function matchesFeast(Season $season, DateTimeImmutable $now, DateTimeZone $tz): bool
    {
        $feast = $season->feast();

        if ($feast === null) {
            return false;
        }

        $year = (int) $now->format('Y');
        $midnight = $now->setTime(0, 0);

        foreach ([$year - 1, $year, $year + 1] as $candidate) {
            $date = Feasts::date($feast, $candidate, $tz);

            if ($date === null) {
                continue;
            }

            $start = $date->modify('-'.$season->window('before').' days');
            $end = $date->modify('+'.$season->window('after').' days');

            if ($midnight >= $start && $midnight <= $end) {
                return true;
            }
        }

        return false;
    }
}
