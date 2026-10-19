# AlfaKit Forms

<p><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-white.svg"><img src="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

> This is a read-only split of the [AlfaKit monorepo](https://github.com/alfasixbh/alfakit/tree/main/packages/plugins/forms). Please open issues and pull requests there.

A form builder for [AlfaKit](https://alfakit.dev) panels, in the spirit of Typeform and Tally. Admins build forms in the panel: questions, choices, uploads, ratings, sections, page breaks for multi-step forms and conditional logic. The public fills them **outside the panel**, on `/forms/{slug}` or on any Inertia page of your app, in React, Vue or Svelte. Every submission is validated again on the server against the stored definition.

## Installation

```bash
composer require alfasix/alfakit-forms
php artisan vendor:publish --tag=alfakit-forms-migrations
php artisan migrate
php artisan alfakit-forms:page
```

## Register

```php
use AlfaSix\AlfakitForms\FormsPlugin;

$panel->plugin(
    FormsPlugin::make()
        ->navigationGroup('Content')
        ->authorize(fn (User $user): bool => $user->is_admin),
);
```

## Embed a form anywhere

```php
use AlfaSix\AlfakitForms\AlfakitForms;

Route::get('/', fn () => Inertia::render('home', [
    'newsletter' => AlfakitForms::embed('newsletter'),
]));
```

```tsx
<AlfakitComponent component={newsletter} className="max-w-lg" />
```

## Features

- 17 blocks: short and long text, email, phone, website, number, dropdown, single and multiple choice, checkbox, yes / no, date, file upload, rating, section heading, paragraph, page break.
- Per-field label, help, placeholder, width, required and validation (lengths, ranges, choices, dates, file types and sizes, formats picked from a whitelist; admins never type rule strings or regular expressions).
- Conditional logic (all / any rules, eight operators) evaluated in PHP; hidden fields are neither validated nor stored.
- Multi-step wizard with server-side validation of every step.
- Confirmation message or `http(s)` redirect, publishing dates, submission limits.
- Honeypot, time check, per-IP rate limiting and an optional captcha hook.
- Queued email notifications, a `FormSubmitted` event, CSV / XLSX exports, spam review, stats (views, submissions, conversion).
- Tenant-aware, policy or closure authorisation, 13 languages, right-to-left and mobile friendly.

Read the [documentation](https://alfakit.dev/docs/plugins/forms).

## License

MIT. See [LICENSE.md](LICENSE.md).
