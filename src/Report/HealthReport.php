<?php

namespace Ernestdefoe\LogoManager\Report;

use DateTimeImmutable;
use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Logo\Dimensions;
use Ernestdefoe\LogoManager\Logo\Favicons;
use Ernestdefoe\LogoManager\Logo\LogoStore;
use Ernestdefoe\LogoManager\Season\Schedule;
use Ernestdefoe\LogoManager\Season\Season;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * What is wrong, or about to be, with the logos and the schedule.
 *
 * Built when the studio opens, never on a forum page: it reads every file it
 * checks, and walks the schedule a day at a time for a year ahead. That is
 * cheap for one administrator once, and pointless for every visitor.
 *
 * Items carry a translation key and parameters rather than sentences, so the
 * studio words them in the administrator's own language.
 */
class HealthReport
{
    /** How far ahead a season change is called out. */
    public const SOON = 14;

    protected array $items = [];

    public function __construct(
        protected Config $config,
        protected Schedule $schedule,
        protected LogoStore $store,
        protected Dimensions $dimensions,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function build(?DateTimeImmutable $today = null): array
    {
        $this->items = [];

        if (! $this->config->enabled()) {
            $this->add('info', 'disabled');
        }

        $this->file($this->settings->get('logo_path'), 'logo_light');
        $this->file($this->settings->get('logo_dark_mode_path'), 'logo_dark');
        $this->file($this->settings->get('email_logo_path'), 'email');
        $this->file($this->settings->get('logo_email_copy_path'), 'email');
        $this->file($this->settings->get('favicon_path'), 'favicon');

        foreach ($this->config->favicon() ?? [] as $key => $file) {
            if ($key !== 'icon') {
                $this->file($file, 'favicon');
            }
        }

        $this->email();

        $rules = array_map(fn ($rule) => new Season($rule, $this->config), $this->config->seasonRules());
        $problems = [];

        foreach ($rules as $season) {
            $problems[$season->id()] = $this->season($season);
        }

        [$now, $next] = $rules !== [] && $this->config->seasonsEnabled()
            ? $this->timeline($rules, $problems, $today)
            : [null, null];

        if ($rules !== [] && ! $this->config->seasonsEnabled()) {
            $this->add('info', 'seasons_off');
        }

        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($this->items, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return [
            'items' => $this->items,
            'now' => $now,
            'next' => $next,
            'seasons' => count($rules),
            'checkedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @return int how many problems this season's own files have */
    protected function season(Season $season): int
    {
        $before = count($this->items);
        $params = ['season' => $season->name()];

        if ($season->enabled() && ! $this->runs($season)) {
            $this->add('warning', 'never_runs', $params);
        }

        $this->file($season->logo('light'), 'logo_light', $params);
        $this->file($season->logo('dark'), 'logo_dark', $params);
        $this->file($season->email()['file'] ?? null, 'email', $params);

        foreach (array_unique(array_filter($season->favicon() ?? [])) as $file) {
            $this->file($file, 'favicon', $params);
        }

        return count($this->items) - $before;
    }

    protected function runs(Season $season): bool
    {
        return $season->whenType() === 'feast'
            ? $season->feast() !== null
            : $season->monthDay('from') !== null && $season->monthDay('to') !== null;
    }

    /**
     * Walk the next year a day at a time: what is running now, what changes
     * next and when, and which enabled seasons never get a day because an
     * earlier rule always wins.
     */
    protected function timeline(array $rules, array $problems, ?DateTimeImmutable $today): array
    {
        $days = $this->schedule->upcoming(366, $today);
        $winners = [];
        $first = reset($days);
        $now = $first?->id();
        $next = null;
        $index = 0;

        foreach ($days as $date => $season) {
            $id = $season?->id();
            $winners[$id ?? ''] = true;

            if ($next === null && $id !== $now) {
                $next = ['id' => $id, 'name' => $season?->name(), 'date' => $date, 'days' => $index];
            }

            $index++;
        }

        foreach ($rules as $season) {
            if ($season->enabled() && $this->runs($season) && ! isset($winners[$season->id()])) {
                $this->add('warning', 'shadowed', ['season' => $season->name()]);
            }
        }

        if ($next !== null && $next['days'] <= self::SOON) {
            $broken = $next['id'] !== null && ($problems[$next['id']] ?? 0) > 0;
            $key = $next['id'] === null ? 'ending_soon' : ($broken ? 'upcoming_broken' : 'upcoming');

            $this->add($broken ? 'error' : 'info', $key, [
                'season' => $next['name'] ?? $first?->name(),
                'date' => $next['date'],
                'days' => $next['days'],
            ]);
        }

        return [$first === null ? null : ['id' => $now, 'name' => $first->name()], $next];
    }

    /**
     * Mail clients show neither SVG nor WebP, and core only falls back to the
     * header logo itself when it is neither — so this combination means
     * every email goes out with no logo at all.
     */
    protected function email(): void
    {
        $logo = (string) $this->settings->get('logo_path');

        if ($logo === ''
            || $this->settings->get('email_logo_path')
            || $this->settings->get('logo_email_copy_path')) {
            return;
        }

        if (preg_match('/\.(svg|webp)$/i', $logo)) {
            $this->add('warning', 'email_no_logo');
        }
    }

    protected function file(?string $path, string $slot, array $params = []): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $params += ['slot' => $slot, 'file' => $path];

        if ($this->config->filename($path) === null) {
            $this->add('error', 'missing_file', $params);

            return;
        }

        $bytes = $this->store->read($path);

        if ($bytes === null) {
            $this->add('error', 'missing_file', $params);
        } elseif (! Favicons::isIco($bytes) && $this->dimensions->fromBytes($path, $bytes) === null) {
            $this->add('error', 'broken_file', $params);
        }
    }

    protected function add(string $level, string $key, array $params = []): void
    {
        $this->items[] = ['level' => $level, 'key' => $key, 'params' => $params];
    }
}
