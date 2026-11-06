<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## PayMef API

JSON API for the PayMef school payments platform. All routes live under `/api` and use Sanctum bearer tokens unless noted.

### Roles

`admin` (platform), `school_admin` (one school), `student`, `parent` (guardian). Every query is scoped: school staff see their school, students see their own invoices, guardians see their linked children.

### Endpoints

| Method | Path | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/health` | public | liveness |
| POST | `/api/auth/register` | public | students and parents only |
| POST | `/api/auth/login` | public | returns bearer token |
| POST | `/api/auth/forgot-password` | public | generic response, no account enumeration |
| POST | `/api/auth/reset-password` | public | also accepts invite tokens |
| GET | `/api/auth/email/verify/{id}/{hash}` | signed URL | admin accounts are exempt |
| GET | `/api/auth/me` | auth | current user + school |
| PATCH | `/api/auth/profile` | auth | name, phone |
| POST | `/api/auth/password` | auth | requires `current_password`, signs out other devices |
| GET/POST | `/api/invoices` | auth / school staff | list (filter `status`, `student_id`, `school_id`), create |
| POST | `/api/invoices/bulk` | school staff | up to 200 invoices, all-or-nothing |
| GET | `/api/invoices/{invoice}` | scoped | includes `outstanding_minor` |
| PATCH | `/api/invoices/{invoice}` | school staff | description, due date, amount (amount locked once paid) |
| POST | `/api/invoices/{invoice}/issue` | school staff | draft → issued |
| POST | `/api/invoices/{invoice}/cancel` | school staff | only while nothing is paid |
| POST | `/api/invoices/{invoice}/pay` | scoped | optional `amount_minor`, optional `Idempotency-Key` |
| GET | `/api/payments` | scoped | history, filter `status`, `invoice_id` |
| GET | `/api/payments/{reference}` | scoped | re-verifies with Paystack |
| POST | `/api/payments/{reference}/refund` | school staff | full or partial `amount_minor` |
| POST | `/api/webhooks/paystack` | public | HMAC-SHA512 over the raw body |
| GET | `/api/wallet` | auth | own balance |
| GET | `/api/schools/{school}/wallet` | scoped | school balance |
| GET | `/api/schools/{school}/summary` | scoped | invoiced / collected / outstanding |
| GET | `/api/ledger` | scoped | paginated entries, filter `entry_type` |
| GET | `/api/children` | parent | linked students + outstanding |
| GET/POST/DELETE | `/api/students/{student}/guardians` | school staff | manage guardian links |
| GET/POST | `/api/schools` | admin (create) | list is scoped |
| PATCH | `/api/schools/{school}` | admin | name, slug, verification status |
| POST | `/api/schools/{school}/admins` | admin | provisions a school admin and emails an invite |
| GET/POST | `/api/students` | school staff | list (filter `q`, `school_id`), provision one |
| POST | `/api/students/bulk` | school staff | up to 500, duplicates reported as `skipped` |

### Money model

Amounts are integers in the minor unit (`amount_minor`, kobo). Invoices are never mutated by a payment: successful payment attempts post immutable `ledger_entries` rows with a unique `idempotency_key`, so a replayed webhook cannot credit twice. Refunds post a compensating `refund` entry. Balances are always derived from the ledger, never stored.

### Background work

Queued mail (receipts, invites, reminders) needs a worker, and the scheduled jobs need an entry in cron:

```sh
php artisan queue:work --tries=3
# crontab
* * * * * cd /path/to/paymef-backend && php artisan schedule:run >> /dev/null 2>&1
```

| Command | Schedule | Purpose |
| --- | --- | --- |
| `payments:reconcile` | every 15 min | re-verify pending attempts so a lost webhook cannot strand money |
| `invoices:mark-overdue` | hourly | issued/partially paid past due → `overdue` |
| `invoices:send-reminders` | daily 08:00 | students and guardians, 3-day cooldown per invoice |
| `sanctum:prune-expired` | daily | expire stale tokens |

Paystack must be able to reach `POST /api/webhooks/paystack`; locally that means a tunnel (the panel callback still settles via `GET /api/payments/{reference}` because it verifies server-side).

### Tests

```sh
php artisan test
```
