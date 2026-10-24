<?php

namespace Ernestdefoe\LogoManager\Report;

use DateTimeImmutable;
use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Logo\Dimensions;
use Ernestdefoe\LogoManager\Logo\EmailCopy;
use Ernestdefoe\LogoManager\Logo\Favicons;
use Ernestdefoe\LogoManager\Logo\LogoStore;
use Ernestdefoe\LogoManager\Season\Schedule;
use Ernestdefoe\LogoManager\Season\Season;
use Flarum\Mail\EmailLogo;
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

    /** Core's own email logo, uploaded in Flarum's Email settings. */
    protected const DEDICATED = 'email_logo_path';

    protected array $items = [];

    public function __construct(
        protected Config $config,
        protected Schedule $schedule,
        protected LogoStore $store,
        protected Dimensions $dimensions,
        protected SettingsRepositoryInterface $settings,
        protected EmailCopy $emailCopy,
    ) {
    }

    public function build(?DateTimeImmutable $today = null): array
    {
        $this->items = [];

        // Repairs first, so what follows describes the forum as it now is.
        $healed = $this->emailCopy->heal();

        if (! $this->config->enabled()) {
            $this->add('info', 'disabled');
        }

        $this->file($this->settings->get('logo_path'), 'logo_light');
        $this->file($this->settings->get('logo_dark_mode_path'), 'logo_dark');
        $this->file($this->settings->get('favicon_path'), 'favicon');

        foreach ($this->config->favicon() ?? [] as $key => $file) {
            if ($key !== 'icon') {
                $this->file($file, 'favicon');
            }
        }

        $rules = array_map(fn ($rule) => new Season($rule, $this->config), $this->config->seasonRules());
        $this->email($rules, $healed);
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
        // Not read by emails while Email settings has a logo of its own.
        if (! $this->settings->get(self::DEDICATED)) {
            $this->seasonEmail($season, $params);
        }

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
     * The logo emails actually carry, in core's order: the logo uploaded in
     * Flarum's own Email settings, then the copy of the forum logo, then the
     * forum logo itself if a mail client can show it.
     *
     * 🚨 Only the first of those that is set is used, so only it is checked.
     * A copy behind an uploaded email logo is never mailed, and calling it
     * missing sent people to upload a file that was not the problem.
     *
     * @param Season[] $rules
     * @param string[] $healed
     */
    protected function email(array $rules, array $healed): void
    {
        if (! class_exists(EmailLogo::class)) {
            return;
        }

        if ($this->settings->get(self::DEDICATED)) {
            $problem = $this->problem((string) $this->settings->get(self::DEDICATED));
            $this->add($problem ? 'error' : 'info', $problem ? 'email_settings_'.$problem : 'email_uses_settings');

            return;
        }

        foreach ($rules as $season) {
            if (in_array($season->id(), $healed, true)) {
                $this->add('info', 'email_remade', ['season' => $season->name()]);
            }
        }

        if (in_array('base', $healed, true)) {
            $this->add('info', 'email_remade');
        }

        $logo = (string) $this->settings->get('logo_path');
        $copy = (string) $this->settings->get(EmailLogo::COPY_PATH_KEY);

        if ($copy !== '') {
            $this->file($copy, 'email');

            return;
        }

        if ($logo === '' || ! preg_match('/\.(svg|webp)$/i', $logo)) {
            return;
        }

        $svg = str_ends_with(strtolower($logo), '.svg') ? $this->store->read($logo) : null;

        $this->add('warning', $svg !== null ? 'email_svg_no_copy' : 'email_no_logo', [], $svg !== null ? ['scope' => 'base', 'svg' => $svg] : null);
    }

    protected function seasonEmail(Season $season, array $params): void
    {
        $email = $season->email()['file'] ?? null;

        if (! $email || $this->store->exists($email)) {
            $this->file($email, 'email', $params);

            return;
        }

        // Still missing after heal(): an SVG logo, which only a browser can draw.
        $logo = $season->logo('light');
        $svg = $logo && str_ends_with(strtolower($logo), '.svg') ? $this->store->read($logo) : null;

        $this->add('error', $svg !== null ? 'email_svg_missing' : 'missing_file', $params + ['slot' => 'email', 'fix' => 'seasons'], $svg !== null ? ['scope' => $season->id(), 'svg' => $svg] : null);
    }

    protected function file(?string $path, string $slot, array $params = []): void
    {
        if ($path !== null && $path !== '' && ($problem = $this->problem($path))) {
            // No filename: nobody recognises `logo-email-tg4hjgki.png`, and the
            // message says where to go instead.
            $this->add('error', $problem, $params + ['slot' => $slot, 'fix' => $this->fix($slot, $params)]);
        }
    }

    /** @return 'missing_file'|'broken_file'|null */
    protected function problem(string $path): ?string
    {
        $bytes = $this->config->filename($path) === null ? null : $this->store->read($path);

        if ($bytes === null) {
            return 'missing_file';
        }

        return ! Favicons::isIco($bytes) && $this->dimensions->fromBytes($path, $bytes) === null ? 'broken_file' : null;
    }

    /** Where the administrator goes to put it right. */
    protected function fix(string $slot, array $params): string
    {
        return match (true) {
            isset($params['season']) => 'seasons',
            $slot === 'favicon' => 'favicon',
            default => 'logo',
        };
    }

    /** @param array{scope:string,svg:string}|null $action the Report's "Make the email copy" button */
    protected function add(string $level, string $key, array $params = [], ?array $action = null): void
    {
        $this->items[] = ['level' => $level, 'key' => $key, 'params' => $params] + ($action ? ['action' => $action] : []);
    }
}
